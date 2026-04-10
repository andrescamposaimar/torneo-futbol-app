<?php
/**
 * Plugin Name: Entre Redes API
 * Description: REST API endpoints para la app móvil Entre Redes (Liga Escolar Colegio Marianista).
 * Version: 1.0.0
 * Author: Entre Redes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────

/**
 * Cachea una respuesta REST usando transients de WordPress.
 */
function cachear_respuesta_rest( string $key, callable $callback, int $ttl = 3600 ) {
    $cached = get_transient( $key );
    if ( $cached !== false ) {
        return $cached;
    }

    $result = $callback();

    // No cachear instancias de WP_Error
    if ( ! is_wp_error( $result ) ) {
        set_transient( $key, $result, $ttl );
    }

    return $result;
}

/**
 * Invalida todos los transients de la lista de jugadores (v3) y del jugador individual.
 * Se dispara automáticamente al guardar/actualizar un sp_player en el admin.
 */
add_action( 'save_post_sp_player', function ( int $post_id ) {
    global $wpdb;
    // Eliminar transient individual de este jugador
    delete_transient( 'jugador_por_id_' . $post_id );
    // Eliminar todos los transients del listado (v3) para que se recalculen con datos frescos
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_entre_redes_jugadores_v3_%'
            OR option_name LIKE '_transient_timeout_entre_redes_jugadores_v3_%'"
    );
} );

/**
 * Devuelve [equipo_id, equipo_nombre, escudo_url] para un jugador dado.
 * Busca primero por sp_list (vínculo equipo↔jugador) y luego por meta sp_team.
 */
function obtener_equipo_desde_rest( int $player_id ): array {
    $transient_key = 'equipo_jugador_' . $player_id;
    $cached = get_transient( $transient_key );

    if ( $cached !== false && is_array( $cached ) ) {
        return $cached;
    }

    // Buscar listas (sp_list) en las que esté el jugador
    $listas = get_the_terms( $player_id, 'sp_list' );
    if ( ! empty( $listas ) && is_array( $listas ) ) {
        foreach ( $listas as $lista ) {
            $equipo_query = new WP_Query( [
                'post_type'      => 'sp_team',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [ [
                    'key'     => 'sp_list',
                    'value'   => $lista->term_id,
                    'compare' => 'LIKE',
                ] ],
            ] );

            if ( $equipo_query->have_posts() ) {
                $equipo_post = $equipo_query->posts[0];
                $equipo_id   = $equipo_post->ID;
                $equipo      = $equipo_post->post_title;
                $escudo      = get_the_post_thumbnail_url( $equipo_id, 'thumbnail' ) ?: '';

                $resultado = [ $equipo_id, $equipo, $escudo ];
                set_transient( $transient_key, $resultado, 7 * DAY_IN_SECONDS );
                return $resultado;
            }
        }
    }

    // Fallback: campo meta sp_team
    $equipo_id = get_post_meta( $player_id, 'sp_team', true );
    if ( $equipo_id ) {
        $equipo_post = get_post( $equipo_id );
        $equipo      = $equipo_post ? $equipo_post->post_title : '';
        $escudo      = $equipo_post ? get_the_post_thumbnail_url( $equipo_id, 'thumbnail' ) ?: '' : '';

        $resultado = [ (int) $equipo_id, $equipo, $escudo ];
        set_transient( $transient_key, $resultado, 7 * DAY_IN_SECONDS );
        return $resultado;
    }

    return [ null, '', '' ];
}

// ─────────────────────────────────────────────────────────────────
// EXPONER CPTs EN LA REST API DE WORDPRESS
// ─────────────────────────────────────────────────────────────────

function entre_redes_expose_custom_post_types() {
    $post_types = [ 'sp_event', 'sp_team', 'sp_player', 'sp_table' ];

    foreach ( $post_types as $pt ) {
        global $wp_post_types;
        if ( isset( $wp_post_types[ $pt ] ) ) {
            $wp_post_types[ $pt ]->show_in_rest          = true;
            $wp_post_types[ $pt ]->rest_base             = $pt;
            $wp_post_types[ $pt ]->rest_controller_class = 'WP_REST_Posts_Controller';
        }
    }
}
add_action( 'init', 'entre_redes_expose_custom_post_types', 25 );

// ─────────────────────────────────────────────────────────────────
// REGISTRO DE RUTAS
// ─────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    $ns = 'entre-redes/v1';

    register_rest_route( $ns, '/temporadas', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_temporadas',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/ligas', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_ligas',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/equipos', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_equipos',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/partidos', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_partidos',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/partidos-programados', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_partidos_programados',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/partidos-equipo', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_partidos_por_equipo',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/partidos-jugador', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_partidos_por_jugador',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/zonas', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_zonas',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/jugadores', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_jugadores',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/jugadores/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_jugador_por_id',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'validate_callback' => fn( $v ) => is_numeric( $v ),
            ],
        ],
    ] );

    register_rest_route( $ns, '/goleadores', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_goleadores',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/tabla-goleadores', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_tabla_goleadores',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/tabla-imbatibles', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_tabla_imbatibles',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( $ns, '/tablas', [
        'methods'             => 'GET',
        'callback'            => 'entre_redes_get_tablas',
        'permission_callback' => '__return_true',
    ] );

    // Endpoint admin: borrar caché de goleadores de un partido
    register_rest_route( $ns, '/borrar-cache-goleadores', [
        'methods'             => 'POST',
        'callback'            => 'entre_redes_borrar_cache_goleadores_partido',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'args'                => [
            'partido_id' => [
                'required'          => true,
                'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
            ],
        ],
    ] );
} );

// ─────────────────────────────────────────────────────────────────
// ENDPOINTS
// ─────────────────────────────────────────────────────────────────

function entre_redes_get_temporadas( WP_REST_Request $request ) {
    return cachear_respuesta_rest( 'entre_redes_temporadas_v2', function () {
        $current_season_id = (int) get_option( 'sportspress_season' );

        $terms = get_terms( [
            'taxonomy'   => 'sp_season',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $terms ) ) return [];

        $filtered = array_filter( $terms, fn( $term ) => $term->term_id >= 149 );

        return array_values( array_map( fn( $term ) => [
            'id'         => $term->term_id,
            'name'       => $term->name,
            'is_current' => $term->term_id === $current_season_id,
        ], $filtered ) );
    }, 2592000 ); // Cache 30 días
}

function entre_redes_get_ligas( WP_REST_Request $request ) {
    $temporada = $request->get_param( 'temporada' );
    $cache_key = 'entre_redes_ligas_v1_' . md5( (string) $temporada );

    return cachear_respuesta_rest( $cache_key, function () use ( $temporada ) {
        $terms = get_terms( [ 'taxonomy' => 'sp_league', 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) ) return [];

        $data = [];

        foreach ( $terms as $term ) {
            $post_ids   = get_objects_in_term( $term->term_id, 'sp_league' );
            $season_ids = [];
            foreach ( $post_ids as $post_id ) {
                $season_ids = array_merge( $season_ids, wp_get_post_terms( $post_id, 'sp_season', [ 'fields' => 'ids' ] ) );
            }
            $season_ids = array_unique( $season_ids );

            if ( $temporada && ! in_array( (int) $temporada, $season_ids ) ) continue;

            $data[] = [
                'id'      => $term->term_id,
                'name'    => $term->name,
                'seasons' => $season_ids,
            ];
        }

        return $data;
    }, 2592000 ); // Cache 30 días
}

function entre_redes_get_equipos( WP_REST_Request $request ) {
    $params = [
        'page'      => max( 1, (int) $request->get_param( 'page' ) ),
        'per_page'  => max( 1, (int) $request->get_param( 'per_page' ) ),
        'temporada' => (int) $request->get_param( 'temporada' ),
        'liga'      => (int) $request->get_param( 'liga' ),
    ];
    $cache_key = 'entre_redes_equipos_v1_' . md5( json_encode( $params ) );

    return cachear_respuesta_rest( $cache_key, function () use ( $params ) {
        $args = [
            'post_type'      => 'sp_team',
            'posts_per_page' => $params['per_page'],
            'paged'          => $params['page'],
            'post_status'    => 'publish',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'tax_query'      => [],
        ];

        if ( $params['temporada'] ) {
            $args['tax_query'][] = [
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => $params['temporada'],
            ];
        }

        if ( $params['liga'] ) {
            $args['tax_query'][] = [
                'taxonomy' => 'sp_league',
                'field'    => 'term_id',
                'terms'    => $params['liga'],
            ];
        }

        if ( count( $args['tax_query'] ) > 1 ) {
            $args['tax_query']['relation'] = 'AND';
        }

        $query = new WP_Query( $args );

        $data = array_map( function ( $post ) {
            $season_terms = wp_get_post_terms( $post->ID, 'sp_season' );
            $league_terms = wp_get_post_terms( $post->ID, 'sp_league' );

            return [
                'id'      => $post->ID,
                'nombre'  => get_the_title( $post ),
                'link'    => get_permalink( $post ),
                'imagen'  => get_the_post_thumbnail_url( $post->ID, 'medium' ),
                'leagues' => array_map( fn( $term ) => [
                    'id'   => $term->term_id,
                    'name' => $term->name,
                ], $league_terms ),
                'seasons' => array_map( fn( $term ) => [
                    'id'   => $term->term_id,
                    'name' => $term->name,
                ], $season_terms ),
            ];
        }, $query->posts );

        return [
            'items'        => $data,
            'total'        => (int) $query->found_posts,
            'total_pages'  => (int) $query->max_num_pages,
            'current_page' => $params['page'],
            'per_page'     => $params['per_page'],
        ];
    }, 2592000 ); // Cache 30 días
}

function entre_redes_get_partidos( WP_REST_Request $request ) {
    $params = [
        'page'      => max( 1, (int) $request->get_param( 'page' ) ),
        'per_page'  => max( 5, (int) $request->get_param( 'per_page' ) ?: 16 ),
        'temporada' => (int) $request->get_param( 'temporada' ),
        'liga'      => (int) $request->get_param( 'liga' ),
        'equipo'    => trim( (string) $request->get_param( 'equipo' ) ),
    ];
    $cache_key = 'entre_redes_partidos_v1_' . md5( json_encode( $params ) );

    return cachear_respuesta_rest( $cache_key, function () use ( $params ) {
        $args = [
            'post_type'      => 'sp_event',
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $params['per_page'],
            'paged'          => $params['page'],
            'tax_query'      => [],
        ];

        if ( $params['temporada'] ) {
            $args['tax_query'][] = [
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => $params['temporada'],
            ];
        }

        if ( $params['liga'] ) {
            $args['tax_query'][] = [
                'taxonomy' => 'sp_league',
                'field'    => 'term_id',
                'terms'    => $params['liga'],
            ];
        }

        if ( count( $args['tax_query'] ) > 1 ) {
            $args['tax_query']['relation'] = 'AND';
        }

        $query      = new WP_Query( $args );
        $resultados = [];

        while ( $query->have_posts() ) {
            $query->the_post();
            $event_id = get_the_ID();
            $titulo   = get_the_title( $event_id );

            $titulo_limpio = html_entity_decode( $titulo, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $titulo_limpio = str_replace( [ ' – ', ' — ', '–', '—', ' - ', '‐' ], ' || ', $titulo_limpio );
            $equipos       = explode( ' || ', $titulo_limpio );
            $equipo_local    = trim( $equipos[0] ?? '' );
            $equipo_visitante = trim( $equipos[1] ?? '' );

            if ( $params['equipo'] && strcasecmp( $equipo_local, $params['equipo'] ) !== 0 && strcasecmp( $equipo_visitante, $params['equipo'] ) !== 0 ) {
                continue;
            }

            $post_local    = get_page_by_title( $equipo_local, OBJECT, 'sp_team' );
            $post_visitante = get_page_by_title( $equipo_visitante, OBJECT, 'sp_team' );

            $liga_term   = wp_get_post_terms( $event_id, 'sp_league', [ 'fields' => 'names' ] );
            $liga_nombre = is_array( $liga_term ) && ! empty( $liga_term ) ? $liga_term[0] : '';

            $goles_local = $goles_visitante = null;

            $g1 = get_post_meta( $event_id, 'sp_score_1', true );
            $g2 = get_post_meta( $event_id, 'sp_score_2', true );
            if ( $g1 !== '' ) $goles_local     = (int) $g1;
            if ( $g2 !== '' ) $goles_visitante = (int) $g2;

            if ( $goles_local === null || $goles_visitante === null ) {
                $rest_response = wp_remote_get( rest_url( "wp/v2/sp_event/{$event_id}" ) );
                if ( ! is_wp_error( $rest_response ) ) {
                    $data = json_decode( wp_remote_retrieve_body( $rest_response ), true );
                    if ( ! empty( $data['main_results'] ) && is_array( $data['main_results'] ) && count( $data['main_results'] ) === 2 ) {
                        $goles_local     = (int) $data['main_results'][0];
                        $goles_visitante = (int) $data['main_results'][1];
                    }
                }
            }

            $resultados[] = [
                'id'               => $event_id,
                'fecha'            => get_the_date( 'Y-m-d', $event_id ),
                'hora'             => get_the_time( 'H:i', $event_id ),
                'cancha'           => get_post_meta( $event_id, 'cancha', true ),
                'liga'             => $liga_nombre,
                'equipo_local'     => $equipo_local,
                'equipo_visitante' => $equipo_visitante,
                'escudo_local'     => $post_local ? get_the_post_thumbnail_url( $post_local->ID, 'thumbnail' ) : false,
                'escudo_visitante' => $post_visitante ? get_the_post_thumbnail_url( $post_visitante->ID, 'thumbnail' ) : false,
                'goles_local'      => $goles_local,
                'goles_visitante'  => $goles_visitante,
                'status'           => get_post_status( $event_id ),
            ];
        }

        wp_reset_postdata();

        return [
            'total'        => $query->found_posts,
            'total_pages'  => $query->max_num_pages,
            'current_page' => $params['page'],
            'per_page'     => $params['per_page'],
            'items'        => $resultados,
        ];
    }, 432000 ); // Cache 5 días
}

function entre_redes_get_partidos_programados( WP_REST_Request $request ) {
    $paged    = max( 1, (int) $request->get_param( 'page' ) );
    $per_page = max( 5, (int) $request->get_param( 'per_page' ) ?: 32 );
    $fecha    = sanitize_text_field( $request->get_param( 'fecha' ) );
    $equipo   = $request->get_param( 'equipo' );

    $args = [
        'post_type'      => 'sp_event',
        'post_status'    => 'future',
        'orderby'        => 'date',
        'order'          => 'ASC',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'tax_query'      => [],
    ];

    if ( ! empty( $fecha ) ) {
        $args['date_query'] = [ [
            'year'  => date( 'Y', strtotime( $fecha ) ),
            'month' => date( 'm', strtotime( $fecha ) ),
            'day'   => date( 'd', strtotime( $fecha ) ),
        ] ];
    }

    if ( $request->get_param( 'temporada' ) ) {
        $args['tax_query'][] = [
            'taxonomy' => 'sp_season',
            'field'    => 'term_id',
            'terms'    => (int) $request->get_param( 'temporada' ),
        ];
    }

    if ( $request->get_param( 'liga' ) ) {
        $args['tax_query'][] = [
            'taxonomy' => 'sp_league',
            'field'    => 'term_id',
            'terms'    => (int) $request->get_param( 'liga' ),
        ];
    }

    if ( count( $args['tax_query'] ) > 1 ) {
        $args['tax_query']['relation'] = 'AND';
    }

    $query      = new WP_Query( $args );
    $resultados = [];

    while ( $query->have_posts() ) {
        $query->the_post();
        $event_id = get_the_ID();
        $titulo   = get_the_title( $event_id );

        $titulo_decoded    = html_entity_decode( $titulo, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $titulo_normalized = str_replace(
            [ ' – ', ' — ', ' - ', ' &#8211; ', ' &#8212; ', ' &#x2013; ', ' &#x2014; ', ' \u2013 ', ' \u2014 ', '–', '—', '-' ],
            '|',
            $titulo_decoded
        );
        $equipos = explode( '|', $titulo_normalized );

        $equipo_local     = trim( $equipos[0] ?? '' );
        $equipo_visitante = trim( $equipos[1] ?? '' );

        if ( empty( $equipo_local ) || empty( $equipo_visitante ) ) continue;

        if ( $equipo && strcasecmp( $equipo_local, $equipo ) !== 0 && strcasecmp( $equipo_visitante, $equipo ) !== 0 ) {
            continue;
        }

        $post_local     = get_page_by_title( $equipo_local, OBJECT, 'sp_team' );
        $post_visitante = get_page_by_title( $equipo_visitante, OBJECT, 'sp_team' );

        $liga_term   = wp_get_post_terms( $event_id, 'sp_league', [ 'fields' => 'names' ] );
        $liga_nombre = is_array( $liga_term ) && ! empty( $liga_term ) ? $liga_term[0] : '';

        $cancha_term   = wp_get_post_terms( $event_id, 'sp_venue', [ 'fields' => 'names' ] );
        $cancha_nombre = is_array( $cancha_term ) && ! empty( $cancha_term ) ? $cancha_term[0] : '';

        $resultados[] = [
            'id'               => $event_id,
            'fecha'            => get_the_date( 'Y-m-d', $event_id ),
            'hora'             => get_the_time( 'H:i', $event_id ),
            'cancha'           => $cancha_nombre,
            'liga'             => $liga_nombre,
            'equipo_local'     => $equipo_local,
            'equipo_visitante' => $equipo_visitante,
            'escudo_local'     => $post_local ? get_the_post_thumbnail_url( $post_local->ID, 'thumbnail' ) : false,
            'escudo_visitante' => $post_visitante ? get_the_post_thumbnail_url( $post_visitante->ID, 'thumbnail' ) : false,
            'goles_local'      => null,
            'goles_visitante'  => null,
        ];
    }

    wp_reset_postdata();

    return [
        'total'        => $query->found_posts,
        'total_pages'  => $query->max_num_pages,
        'current_page' => $paged,
        'per_page'     => $per_page,
        'items'        => $resultados,
    ];
}

function entre_redes_get_partidos_por_equipo( WP_REST_Request $request ) {
    $equipo_id = (int) $request->get_param( 'equipo_id' );
    $equipo    = sanitize_text_field( $request->get_param( 'equipo' ) );

    if ( $equipo_id ) {
        $post = get_post( $equipo_id );
        if ( $post && $post->post_type === 'sp_team' ) {
            $equipo = $post->post_title;
        } else {
            return new WP_Error( 'invalid_equipo_id', 'El ID de equipo no es válido', [ 'status' => 400 ] );
        }
    }

    if ( ! $equipo ) {
        return new WP_Error( 'no_equipo', 'Debes proporcionar un ID o nombre de equipo', [ 'status' => 400 ] );
    }

    $temporada = $request->get_param( 'temporada' );
    $cache_key = 'partidos_equipo_' . md5( json_encode( [
        'equipo_id' => $equipo_id,
        'equipo'    => $equipo,
        'temporada' => $temporada,
    ] ) );

    return cachear_respuesta_rest( $cache_key, function () use ( $equipo, $temporada ) {
        $tax_query = [];

        if ( $temporada ) {
            $tax_query[] = [
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => (int) $temporada,
            ];
        }

        $args = [
            'post_type'      => 'sp_event',
            'post_status'    => [ 'publish', 'future' ],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => $tax_query,
        ];

        $query      = new WP_Query( $args );
        $resultados = [];

        while ( $query->have_posts() ) {
            $query->the_post();
            $event_id = get_the_ID();
            $teams    = get_post_meta( $event_id, 'sp_team' );

            if ( ! is_array( $teams ) ) continue;

            $participa = false;
            foreach ( $teams as $team_id ) {
                $post_team = get_post( $team_id );
                if ( $post_team && strcasecmp( $post_team->post_title, $equipo ) === 0 ) {
                    $participa = true;
                    break;
                }
            }

            if ( ! $participa ) continue;

            $equipo_local_id    = $teams[0] ?? null;
            $equipo_visitante_id = $teams[1] ?? null;

            $equipo_local    = $equipo_local_id ? get_the_title( $equipo_local_id ) : '';
            $equipo_visitante = $equipo_visitante_id ? get_the_title( $equipo_visitante_id ) : '';

            $post_local     = $equipo_local_id ? get_post( $equipo_local_id ) : null;
            $post_visitante = $equipo_visitante_id ? get_post( $equipo_visitante_id ) : null;

            $liga_term   = wp_get_post_terms( $event_id, 'sp_league', [ 'fields' => 'names' ] );
            $liga_nombre = is_array( $liga_term ) && ! empty( $liga_term ) ? $liga_term[0] : '';

            $goles_local = $goles_visitante = null;

            $sp_results = get_post_meta( $event_id, 'sp_results', true );
            if ( is_array( $sp_results ) ) {
                $sr = [];
                foreach ( $sp_results as $k => $v ) {
                    $sr[ (string) $k ] = $v;
                }
                if ( $equipo_local_id && isset( $sr[ $equipo_local_id ]['goals'] ) ) {
                    $goles_local = (int) $sr[ $equipo_local_id ]['goals'];
                }
                if ( $equipo_visitante_id && isset( $sr[ $equipo_visitante_id ]['goals'] ) ) {
                    $goles_visitante = (int) $sr[ $equipo_visitante_id ]['goals'];
                }
            }

            $resultados[] = [
                'id'               => $event_id,
                'fecha'            => get_the_date( 'Y-m-d', $event_id ),
                'hora'             => get_the_time( 'H:i', $event_id ),
                'cancha'           => get_post_meta( $event_id, 'cancha', true ),
                'liga'             => $liga_nombre,
                'equipo_local'     => $equipo_local,
                'equipo_visitante' => $equipo_visitante,
                'escudo_local'     => $post_local ? get_the_post_thumbnail_url( $post_local->ID, 'thumbnail' ) : false,
                'escudo_visitante' => $post_visitante ? get_the_post_thumbnail_url( $post_visitante->ID, 'thumbnail' ) : false,
                'goles_local'      => $goles_local,
                'goles_visitante'  => $goles_visitante,
            ];
        }

        wp_reset_postdata();
        return $resultados;
    }, 2592000 ); // Cache 30 días
}

function entre_redes_get_partidos_por_jugador( WP_REST_Request $request ) {
    global $wpdb;

    $jugador_id = (int) $request->get_param( 'jugador' );
    if ( ! $jugador_id ) {
        return new WP_Error( 'invalid_player', 'Jugador no especificado', [ 'status' => 400 ] );
    }

    $page     = max( 1, (int) $request->get_param( 'page' ) );
    $per_page = max( 1, (int) $request->get_param( 'per_page' ) ?: 16 );

    $cache_key = 'partidos_jugador_' . md5( json_encode( [
        'jugador_id' => $jugador_id,
        'page'       => $page,
        'per_page'   => $per_page,
    ] ) );

    return cachear_respuesta_rest( $cache_key, function () use ( $jugador_id, $page, $per_page, $wpdb ) {
        $tabla = 'wpm2_jugador_partido';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $tabla WHERE jugador_id = %d",
            $jugador_id
        ) );

        if ( $total === 0 ) {
            return [ 'items' => [], 'current_page' => $page, 'total_pages' => 0 ];
        }

        $offset     = ( $page - 1 ) * $per_page;
        $partido_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT partido_id FROM $tabla WHERE jugador_id = %d ORDER BY partido_id DESC LIMIT %d OFFSET %d",
            $jugador_id, $per_page, $offset
        ) );

        if ( empty( $partido_ids ) ) {
            return [ 'items' => [], 'current_page' => $page, 'total_pages' => (int) ceil( $total / $per_page ) ];
        }

        $query      = new WP_Query( [
            'post_type'      => 'sp_event',
            'post__in'       => $partido_ids,
            'orderby'        => 'post__in',
            'posts_per_page' => -1,
        ] );
        $resultados = [];

        while ( $query->have_posts() ) {
            $query->the_post();
            $event_id = get_the_ID();
            $teams    = get_post_meta( $event_id, 'sp_team' );

            if ( ! is_array( $teams ) ) continue;

            $equipo_local_id    = $teams[0] ?? null;
            $equipo_visitante_id = $teams[1] ?? null;

            $equipo_local    = $equipo_local_id ? get_the_title( $equipo_local_id ) : '';
            $equipo_visitante = $equipo_visitante_id ? get_the_title( $equipo_visitante_id ) : '';

            $post_local     = $equipo_local_id ? get_post( $equipo_local_id ) : null;
            $post_visitante = $equipo_visitante_id ? get_post( $equipo_visitante_id ) : null;

            $liga_term   = wp_get_post_terms( $event_id, 'sp_league', [ 'fields' => 'names' ] );
            $liga_nombre = is_array( $liga_term ) && ! empty( $liga_term ) ? $liga_term[0] : '';

            $goles_local = $goles_visitante = null;

            $sp_results = get_post_meta( $event_id, 'sp_results', true );
            if ( is_array( $sp_results ) ) {
                $sr = [];
                foreach ( $sp_results as $k => $v ) {
                    $sr[ (string) $k ] = $v;
                }
                if ( $equipo_local_id && isset( $sr[ $equipo_local_id ]['goals'] ) ) {
                    $goles_local = (int) $sr[ $equipo_local_id ]['goals'];
                }
                if ( $equipo_visitante_id && isset( $sr[ $equipo_visitante_id ]['goals'] ) ) {
                    $goles_visitante = (int) $sr[ $equipo_visitante_id ]['goals'];
                }
            }

            $resultados[] = [
                'id'               => $event_id,
                'fecha'            => get_the_date( 'Y-m-d', $event_id ),
                'hora'             => get_the_time( 'H:i', $event_id ),
                'cancha'           => get_post_meta( $event_id, 'cancha', true ),
                'liga'             => $liga_nombre,
                'equipo_local'     => $equipo_local,
                'equipo_visitante' => $equipo_visitante,
                'escudo_local'     => $post_local ? get_the_post_thumbnail_url( $post_local->ID, 'thumbnail' ) : false,
                'escudo_visitante' => $post_visitante ? get_the_post_thumbnail_url( $post_visitante->ID, 'thumbnail' ) : false,
                'goles_local'      => $goles_local,
                'goles_visitante'  => $goles_visitante,
            ];
        }

        wp_reset_postdata();

        return [
            'items'        => $resultados,
            'current_page' => $page,
            'total_pages'  => (int) ceil( $total / $per_page ),
        ];
    }, 86400 ); // Cache 1 día
}

function entre_redes_get_zonas( WP_REST_Request $request ) {
    $temporada = $request->get_param( 'temporada' );
    $response  = wp_remote_get( home_url( '/wp-json/wp/v2/sp_table?per_page=100' ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'request_failed', 'No se pudo obtener la lista de tablas', [ 'status' => 500 ] );
    }

    $tablas = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $tablas ) ) return [];

    $zonas = [];

    foreach ( $tablas as $tabla ) {
        $slug  = strtolower( $tabla['slug'] );
        $title = strtolower( $tabla['title']['rendered'] );

        if ( $temporada && ! str_contains( $slug, (string) $temporada ) ) continue;

        if ( preg_match( '/zona[-\s]?([a-z0-9]+)/i', $slug . ' ' . $title, $match ) ) {
            $zona = strtoupper( $match[1] );
            $zonas[ $zona ] = [
                'id'   => crc32( $zona ),
                'name' => 'Zona ' . $zona,
            ];
        }
    }

    return array_values( $zonas );
}

function entre_redes_get_jugadores( WP_REST_Request $request ) {
    $params = [
        'page'      => (int) ( $request->get_param( 'page' ) ?? 1 ),
        'per_page'  => (int) ( $request->get_param( 'per_page' ) ?? 20 ),
        'temporada' => (int) $request->get_param( 'temporada' ),
        'liga'      => (int) $request->get_param( 'liga' ),
        'equipo_id' => (int) $request->get_param( 'equipo_id' ),
        'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
    ];
    $cache_key = 'entre_redes_jugadores_v3_' . md5( json_encode( $params ) );

    $items = cachear_respuesta_rest( $cache_key, function () use ( $params ) {
        $args = [
            'post_type'      => 'sp_player',
            'post_status'    => 'publish',
            'posts_per_page' => $params['per_page'],
            'paged'          => $params['page'],
            'orderby'        => 'title',
            'order'          => 'ASC',
            'tax_query'      => [],
            'meta_query'     => [],
        ];

        if ( $params['search'] ) {
            $args['s'] = $params['search'];
        }

        if ( $params['temporada'] ) {
            $args['tax_query'][] = [
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => $params['temporada'],
            ];
        }

        if ( $params['liga'] ) {
            $args['tax_query'][] = [
                'taxonomy' => 'sp_league',
                'field'    => 'term_id',
                'terms'    => $params['liga'],
            ];
        }

        if ( $params['equipo_id'] ) {
            $args['meta_query'][] = [
                'key'     => 'sp_team',
                'value'   => (string) $params['equipo_id'],
                'compare' => '=',
            ];
        }

        if ( count( $args['tax_query'] ) > 1 ) {
            $args['tax_query']['relation'] = 'AND';
        }

        $query = new WP_Query( $args );

        $pos_map = [
            3   => 'Arquero',
            5   => 'Defensor',
            8   => 'Mediocampista',
            9   => 'Delantero',
            125 => 'Arquero Sup.',
        ];

        return array_map( function ( $post ) use ( $pos_map ) {
            try {
                $metrics  = get_post_meta( $post->ID, 'sp_metrics', true );
                $date     = get_post_field( 'post_date', $post->ID );
                $fecha_nacimiento = $date ? date( 'Y-m-d', strtotime( $date ) ) : '';

                $positions_array = wp_get_post_terms( $post->ID, 'sp_position', [ 'fields' => 'ids' ] );
                $main_position   = 'Sin Posicion';
                foreach ( $positions_array as $pid ) {
                    if ( isset( $pos_map[ $pid ] ) ) {
                        $main_position = $pos_map[ $pid ];
                        break;
                    }
                }

                $capitan        = in_array( 52, $positions_array );
                $reemplazo_alta = in_array( 155, $positions_array );
                $reemplazo_baja = in_array( 156, $positions_array );

                [ $equipo_id, $equipo, $escudo ] = obtener_equipo_desde_rest( $post->ID );

                return [
                    'id'               => $post->ID,
                    'title'            => [ 'rendered' => get_the_title( $post ) ],
                    'featured_image'   => get_the_post_thumbnail_url( $post->ID, 'medium' ),
                    'equipo_id'        => $equipo_id,
                    'equipo'           => $equipo,
                    'escudo'           => $escudo,
                    'fecha_nacimiento' => $fecha_nacimiento,
                    'temporadas'       => wp_get_post_terms( $post->ID, 'sp_season', [ 'fields' => 'names' ] ),
                    'metrics'          => $metrics ?: [],
                    'posicion'         => $main_position,
                    'capitan'          => $capitan,
                    'reemplazo_alta'   => $reemplazo_alta,
                    'reemplazo_baja'   => $reemplazo_baja,
                ];
            } catch ( Exception $e ) {
                return [
                    'id'    => $post->ID,
                    'title' => [ 'rendered' => get_the_title( $post ) ],
                    'error' => $e->getMessage(),
                ];
            }
        }, $query->posts );
    }, 2592000 ); // Cache 30 días

    // Añadir header x-wp-total para paginación en la app
    $response = new WP_REST_Response( $items );
    $response->header( 'X-WP-Total', count( $items ) );
    return $response;
}

function entre_redes_get_jugador_por_id( $request ) {
    $id        = (int) $request['id'];
    $cache_key = 'jugador_por_id_' . $id;

    return cachear_respuesta_rest( $cache_key, function () use ( $id ) {
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'sp_player' ) {
            return new WP_Error( 'not_found', 'Jugador no encontrado', [ 'status' => 404 ] );
        }

        try {
            $metrics     = get_post_meta( $id, 'sp_metrics', true );
            $puntaje_raw = isset( $metrics['puntaje'] ) ? (string) $metrics['puntaje'] : '0';
            $puntaje     = str_replace( ',', '.', $puntaje_raw );

            $date             = get_post_field( 'post_date', $id );
            $fecha_nacimiento = $date ? date( 'Y-m-d', strtotime( $date ) ) : '';

            $positions = wp_get_post_terms( $id, 'sp_position', [ 'fields' => 'names' ] );
            $posicion  = ! empty( $positions ) ? implode( ', ', $positions ) : '';

            [ $equipo_id, $equipo, $escudo ] = obtener_equipo_desde_rest( $id );

            return [
                'id'               => $id,
                'title'            => [ 'rendered' => get_the_title( $post ) ],
                'featured_image'   => get_the_post_thumbnail_url( $id, 'medium' ),
                'equipo_id'        => $equipo_id,
                'equipo'           => $equipo,
                'escudo'           => $escudo,
                'fecha_nacimiento' => $fecha_nacimiento,
                'temporadas'       => wp_get_post_terms( $id, 'sp_season', [ 'fields' => 'names' ] ),
                'posicion'         => $posicion,
                'metrics'          => [ 'puntaje' => $puntaje ],
            ];
        } catch ( Exception $e ) {
            return [
                'id'    => $id,
                'title' => [ 'rendered' => get_the_title( $post ) ],
                'error' => $e->getMessage(),
            ];
        }
    }, 2592000 ); // Cache 30 días
}

function entre_redes_get_goleadores( WP_REST_Request $request ) {
    $partido_id = (int) $request->get_param( 'partido_id' );
    if ( ! $partido_id ) {
        return new WP_Error( 'invalid_partido', 'ID de partido no válido', [ 'status' => 400 ] );
    }

    $nocache   = $request->get_param( 'nocache' );
    $cache_key = 'goleadores_partido_' . $partido_id;

    if ( $nocache === '1' ) delete_transient( $cache_key );

    return cachear_respuesta_rest( $cache_key, function () use ( $partido_id ) {
        $url      = home_url( "/wp-json/wp/v2/sp_event/{$partido_id}" );
        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'rest_event_error', 'No se pudo obtener el evento', [ 'status' => 500 ] );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $data['performance'] ) || ! isset( $data['teams'] ) ) {
            return new WP_Error( 'invalid_event_structure', 'El evento no contiene datos de performance o equipos', [ 'status' => 400 ] );
        }

        $teams       = $data['teams'];
        $performance = $data['performance'];

        if ( count( $teams ) < 2 ) {
            return new WP_Error( 'invalid_teams', 'No hay dos equipos definidos en el partido', [ 'status' => 400 ] );
        }

        $local_id     = $teams[0];
        $visitante_id = $teams[1];

        $todos_los_ids = array_merge(
            array_keys( $performance[ $local_id ] ?? [] ),
            array_keys( $performance[ $visitante_id ] ?? [] )
        );
        $player_ids = array_filter( array_unique( $todos_los_ids ), fn( $id ) => $id > 0 );

        if ( empty( $player_ids ) ) {
            return new WP_Error( 'no_players', 'No se encontraron jugadores en el partido', [ 'status' => 400 ] );
        }

        $posts = get_posts( [
            'post_type'      => 'sp_player',
            'post__in'       => $player_ids,
            'posts_per_page' => -1,
        ] );

        update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
        update_object_term_cache( $posts, 'sp_position' );

        $pos_map      = [ 3 => 'Arquero', 5 => 'Defensor', 8 => 'Mediocampista', 9 => 'Delantero', 125 => 'Arquero Sup.' ];
        $jugadores_map = [];

        foreach ( $posts as $j ) {
            $id      = $j->ID;
            $metrics = get_post_meta( $id, 'sp_metrics', true );
            $puntaje = isset( $metrics['Puntaje'] ) ? floatval( $metrics['Puntaje'] ) : 0;

            $pos_ids  = wp_get_post_terms( $id, 'sp_position', [ 'fields' => 'ids' ] );
            $posicion = 'Sin Posición';
            foreach ( $pos_ids as $pid ) {
                if ( isset( $pos_map[ $pid ] ) ) {
                    $posicion = $pos_map[ $pid ];
                    break;
                }
            }

            $jugadores_map[ $id ] = [
                'id'              => $id,
                'nombre'          => get_the_title( $id ),
                'foto'            => get_the_post_thumbnail_url( $id, 'thumbnail' ) ?: '',
                'posicion'        => $posicion,
                'puntaje'         => $puntaje,
                'capitan'         => in_array( 52, $pos_ids ),
                'reemplazo_alta'  => in_array( 155, $pos_ids ),
                'reemplazo_baja'  => in_array( 156, $pos_ids ),
            ];
        }

        $procesar_equipo = function ( $team_id ) use ( $performance, $jugadores_map ) {
            $lista = [];
            if ( ! isset( $performance[ $team_id ] ) ) return $lista;

            foreach ( $performance[ $team_id ] as $player_id => $datos ) {
                if ( ! isset( $jugadores_map[ $player_id ] ) ) continue;
                $lista[] = array_merge( $jugadores_map[ $player_id ], [
                    'goles'            => isset( $datos['goles'] ) ? (int) $datos['goles'] : 0,
                    'tarjeta_amarilla' => isset( $datos['tarjetaamarilla'] ) ? (int) $datos['tarjetaamarilla'] : 0,
                    'tarjeta_roja'     => isset( $datos['tarjetaroja'] ) ? (int) $datos['tarjetaroja'] : 0,
                    'figura'           => ! empty( $datos['figura'] ),
                ] );
            }

            return $lista;
        };

        return [
            'equipo_local' => [
                'id'          => $local_id,
                'nombre'      => get_the_title( $local_id ),
                'goleadores'  => $procesar_equipo( $local_id ),
            ],
            'equipo_visitante' => [
                'id'          => $visitante_id,
                'nombre'      => get_the_title( $visitante_id ),
                'goleadores'  => $procesar_equipo( $visitante_id ),
            ],
        ];
    }, 432000 ); // Cache 5 días
}

function entre_redes_get_tabla_goleadores( WP_REST_Request $request ) {
    $temporada_id = (int) $request->get_param( 'id_temporada' );
    $liga_id      = (int) $request->get_param( 'id_liga' );
    $page         = max( 1, (int) $request->get_param( 'page' ) );
    $per_page     = max( 1, (int) $request->get_param( 'per_page' ) ?: 50 );

    $cache_key = 'tabla_goleadores_v3_' . $temporada_id . '_' . $liga_id;

    $goleadores = cachear_respuesta_rest( $cache_key, function () use ( $temporada_id, $liga_id ) {
        $tax_query = [ 'relation' => 'AND' ];

        if ( $temporada_id ) {
            $tax_query[] = [
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => $temporada_id,
            ];
        }

        if ( $liga_id ) {
            $tax_query[] = [
                'taxonomy' => 'sp_league',
                'field'    => 'term_id',
                'terms'    => $liga_id,
            ];
        }

        $query_args = [
            'post_type'      => 'sp_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ];

        if ( count( $tax_query ) > 1 ) {
            $query_args['tax_query'] = $tax_query;
        }

        $eventos    = get_posts( $query_args );
        $goleadores = [];

        foreach ( $eventos as $evento ) {
            $players_data = get_post_meta( $evento->ID, 'sp_players', true );
            if ( ! is_array( $players_data ) ) continue;

            foreach ( $players_data as $team_id => $jugadores ) {
                foreach ( $jugadores as $player_id => $player_data ) {
                    if ( ! $player_id || ! is_array( $player_data ) ) continue;

                    $goles = isset( $player_data['goles'] ) ? (int) $player_data['goles'] : 0;
                    if ( $goles <= 0 ) continue;

                    if ( ! isset( $goleadores[ $player_id ] ) ) {
                        $foto     = get_the_post_thumbnail_url( $player_id, 'thumbnail' ) ?: '';
                        $equipo   = '';
                        $team_ids = get_post_meta( $player_id, 'current_teams', true );
                        if ( is_array( $team_ids ) && ! empty( $team_ids ) ) {
                            $equipo = get_the_title( $team_ids[0] );
                        }

                        $goleadores[ $player_id ] = [
                            'id'     => $player_id,
                            'nombre' => get_the_title( $player_id ),
                            'foto'   => $foto,
                            'equipo' => $equipo,
                            'goles'  => 0,
                        ];
                    }

                    $goleadores[ $player_id ]['goles'] += $goles;
                }
            }
        }

        usort( $goleadores, fn( $a, $b ) => $b['goles'] <=> $a['goles'] );
        return $goleadores;
    }, 2592000 ); // Cache 30 días

    $total       = count( $goleadores );
    $total_pages = (int) ceil( $total / $per_page );
    $offset      = ( $page - 1 ) * $per_page;
    $items       = array_slice( $goleadores, $offset, $per_page );

    return [
        'total'        => $total,
        'total_pages'  => $total_pages,
        'current_page' => $page,
        'per_page'     => $per_page,
        'items'        => $items,
    ];
}

function entre_redes_get_tabla_imbatibles( WP_REST_Request $request ) {
    $temporada = sanitize_text_field( $request->get_param( 'temporada' ) );
    $page      = max( 1, (int) $request->get_param( 'page' ) );
    $per_page  = max( 1, (int) $request->get_param( 'per_page' ) ?: 10 );

    $cache_key = 'tabla_imbatibles_v1_' . md5( json_encode( [ $temporada, $page, $per_page ] ) );

    return cachear_respuesta_rest( $cache_key, function () use ( $temporada, $page, $per_page ) {
        $temporada_id_map = [
            '196' => 20241,
            '181' => 18775,
            '165' => 18833,
            '149' => 17392,
        ];

        if ( $temporada && ! isset( $temporada_id_map[ $temporada ] ) ) {
            return [
                'total'        => 0,
                'total_pages'  => 0,
                'current_page' => $page,
                'per_page'     => $per_page,
                'items'        => [],
            ];
        }

        $endpoint_url = add_query_arg( [ 'per_page' => 100 ], home_url( '/wp-json/wp/v2/sp_table' ) );
        $response     = wp_remote_get( $endpoint_url );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'request_failed', 'No se pudo obtener la tabla de posiciones', [ 'status' => 500 ] );
        }

        $tablas = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $tablas ) ) return [];

        $arqueros = [];

        foreach ( $tablas as $tabla ) {
            $tabla_id = (int) ( $tabla['id'] ?? 0 );

            if ( $temporada ) {
                if ( $tabla_id !== $temporada_id_map[ $temporada ] ) continue;
            } else {
                if ( ! in_array( $tabla_id, array_values( $temporada_id_map ) ) ) continue;
            }

            $data = $tabla['data'] ?? null;
            if ( ! is_array( $data ) ) continue;

            foreach ( $data as $team_id => $info ) {
                if ( ! is_numeric( $team_id ) ) continue;

                $cache_key_arq = 'arquero_equipo_' . $team_id;
                $arquero_data  = get_transient( $cache_key_arq );

                if ( ! $arquero_data ) {
                    $arquero = get_posts( [
                        'post_type'   => 'sp_player',
                        'numberposts' => 1,
                        'meta_query'  => [ [
                            'key'     => 'sp_team',
                            'value'   => (string) $team_id,
                            'compare' => '=',
                        ] ],
                        'tax_query'   => [ [
                            'taxonomy' => 'sp_position',
                            'field'    => 'term_id',
                            'terms'    => 3,
                        ] ],
                    ] );

                    if ( empty( $arquero ) ) continue;

                    $player       = $arquero[0];
                    $arquero_data = [
                        'id'     => $player->ID,
                        'nombre' => get_the_title( $player->ID ),
                        'foto'   => get_the_post_thumbnail_url( $player->ID, 'thumbnail' ) ?: '',
                    ];

                    set_transient( $cache_key_arq, $arquero_data, 7 * DAY_IN_SECONDS );
                }

                $nombre_equipo = get_the_title( $team_id );
                if ( empty( $nombre_equipo ) ) continue;

                $arqueros[] = [
                    'id'              => $arquero_data['id'],
                    'nombre'          => $arquero_data['nombre'],
                    'foto'            => $arquero_data['foto'],
                    'equipo'          => $nombre_equipo,
                    'goles_recibidos' => (int) ( $info['gc'] ?? 0 ),
                ];
            }
        }

        usort( $arqueros, fn( $a, $b ) => $a['goles_recibidos'] <=> $b['goles_recibidos'] );

        $total       = count( $arqueros );
        $total_pages = (int) ceil( $total / $per_page );
        $offset      = ( $page - 1 ) * $per_page;
        $items       = array_slice( $arqueros, $offset, $per_page );

        return [
            'total'        => $total,
            'total_pages'  => $total_pages,
            'current_page' => $page,
            'per_page'     => $per_page,
            'items'        => $items,
        ];
    }, 2592000 ); // Cache 30 días
}

function entre_redes_get_tablas( WP_REST_Request $request ) {
    $paged     = max( 1, (int) $request->get_param( 'page' ) );
    $per_page  = max( 1, (int) $request->get_param( 'per_page' ) ?: 20 );
    $temporada = sanitize_text_field( $request->get_param( 'temporada' ) );
    $zona      = sanitize_text_field( $request->get_param( 'zona' ) );

    $cache_key = 'entre_redes_tablas_v1_' . md5( json_encode( [
        'page'      => $paged,
        'per_page'  => $per_page,
        'temporada' => $temporada,
        'zona'      => $zona,
    ] ) );

    return cachear_respuesta_rest( $cache_key, function () use ( $paged, $per_page, $temporada, $zona ) {
        $args = [
            'post_type'      => 'sp_table',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
        ];

        if ( $temporada && is_numeric( $temporada ) ) {
            $args['tax_query'] = [ [
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => [ (int) $temporada ],
            ] ];
        } elseif ( $temporada ) {
            $args['s'] = $temporada;
        }

        if ( $zona ) {
            $args['s'] = isset( $args['s'] ) ? $args['s'] . ' ' . $zona : $zona;
        }

        $query      = new WP_Query( $args );
        $resultados = [];

        foreach ( $query->posts as $post ) {
            $slug          = $post->post_name;
            $post_id       = $post->ID;
            $transient_key = 'sp_table_data_' . $post_id;

            $data = get_transient( $transient_key );

            if ( $data === false ) {
                $endpoint_url = home_url( "/wp-json/wp/v2/sp_table/{$post_id}" );
                $rest_response = wp_remote_get( $endpoint_url, [ 'timeout' => 30 ] );

                if ( is_wp_error( $rest_response ) ) continue;

                $rest_data = json_decode( wp_remote_retrieve_body( $rest_response ), true );

                if ( json_last_error() !== JSON_ERROR_NONE ) continue;

                $data = $rest_data['data'] ?? null;
                if ( ! is_array( $data ) ) continue;

                set_transient( $transient_key, $data, 6 * HOUR_IN_SECONDS );
            }

            $equipos = [];
            foreach ( $data as $team_id => $info ) {
                if ( ! is_numeric( $team_id ) ) continue;

                $equipos[] = [
                    'id'       => (int) $team_id,
                    'equipo'   => $info['name'] ?? 'Equipo',
                    'logo'     => get_the_post_thumbnail_url( $team_id, 'thumbnail' ),
                    'posicion' => (int) ( $info['pos'] ?? 0 ),
                    'pj'       => (int) ( $info['pj'] ?? 0 ),
                    'pg'       => (int) ( $info['pg'] ?? 0 ),
                    'pe'       => (int) ( $info['pe'] ?? 0 ),
                    'pp'       => (int) ( $info['pp'] ?? 0 ),
                    'gf'       => (int) ( $info['gf'] ?? 0 ),
                    'gc'       => (int) ( $info['gc'] ?? 0 ),
                    'dg'       => (int) ( $info['dg'] ?? 0 ),
                    'pts'      => (int) ( $info['pts'] ?? 0 ),
                ];
            }

            $resultados[] = [
                'id'      => $post_id,
                'slug'    => $slug,
                'titulo'  => get_the_title( $post ),
                'equipos' => $equipos,
            ];
        }

        return [
            'total'        => (int) $query->found_posts,
            'total_pages'  => (int) $query->max_num_pages,
            'current_page' => $paged,
            'per_page'     => $per_page,
            'items'        => $resultados,
        ];
    }, 432000 ); // Cache 5 días
}

function entre_redes_borrar_cache_goleadores_partido( WP_REST_Request $request ) {
    $partido_id = (int) $request->get_param( 'partido_id' );

    if ( ! $partido_id || get_post_type( $partido_id ) !== 'sp_event' ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => 'ID inválido o el post no es del tipo sp_event',
        ], 400 );
    }

    $cache_key = 'goleadores_partido_' . $partido_id;
    $borrado   = delete_transient( $cache_key );

    return new WP_REST_Response( [
        'success'    => true,
        'partido_id' => $partido_id,
        'borrado'    => $borrado,
        'mensaje'    => $borrado
            ? 'Caché eliminada correctamente'
            : 'No había caché para ese partido',
    ] );
}
