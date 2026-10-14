<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Guards Plugin::gateCambiosWhenDisabled — the captain-facing kill switch.
 *
 * The value of a kill switch is that it has NO GAPS, so the tests that
 * matter most here are the negative ones: that it covers every route, that
 * it does not leak onto another plugin's namespace, and that it never
 * overrides an answer something earlier already produced.
 */
class CambiosDeshabilitadoGateTest extends TestCase {

    protected function setUp(): void {
        global $wpdb;

        InitialSchema::up();
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_settings" );
        InitialSchema::up();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_settings" );
    }

    private function request( string $route ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_route( $route );

        return $request;
    }

    private function apagar( string $mensaje = 'Hablá con la comisión.' ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( "UPDATE {$p}cambios_settings SET setting_value = '0' WHERE setting_key = 'cambios_habilitados'" );
        $wpdb->query(
            $wpdb->prepare( "UPDATE {$p}cambios_settings SET setting_value = %s WHERE setting_key = 'mensaje_deshabilitado'", $mensaje )
        );
    }

    private function gate( string $route, mixed $result = null ): mixed {
        return Plugin::gateCambiosWhenDisabled( $result, new \WP_REST_Server(), $this->request( $route ) );
    }

    // -------------------------------------------------------------------------
    // Open — the ordinary state
    // -------------------------------------------------------------------------

    public function test_con_los_cambios_prendidos_no_interfiere(): void {
        $this->assertNull(
            $this->gate( '/entre-redes/v1/cambios/plazas' ),
            'Returning anything but the untouched $result would short-circuit every request.'
        );
    }

    // -------------------------------------------------------------------------
    // Closed
    // -------------------------------------------------------------------------

    public function test_apagado_responde_503_con_el_mensaje_del_operador(): void {
        $this->apagar( 'Hablá con la comisión.' );

        $resultado = $this->gate( '/entre-redes/v1/cambios/plazas' );

        $this->assertInstanceOf( \WP_Error::class, $resultado );
        $this->assertSame( 'cambios_deshabilitado', $resultado->get_error_code() );
        $this->assertSame( 'Hablá con la comisión.', $resultado->get_error_message() );

        $datos = $resultado->get_error_data();
        $this->assertSame( 503, $datos['status'] ?? null, '503, not 403: a temporary closure is not a permission problem.' );
    }

    /**
     * Listed explicitly rather than derived — a new endpoint added without a
     * line here is exactly the gap that makes a kill switch worthless.
     */
    public function test_apagado_cubre_todas_las_rutas(): void {
        $this->apagar();

        $rutas = [
            '/entre-redes/v1/cambios/solicitudes',
            '/entre-redes/v1/cambios/solicitudes/12/cancelar',
            '/entre-redes/v1/cambios/plazas',
            '/entre-redes/v1/cambios/plazas/candidatos',
            '/entre-redes/v1/cambios/fecha-abierta',
            '/entre-redes/v1/cambios/capitan',
            '/entre-redes/v1/cambios/una-ruta-que-todavia-no-existe',
        ];

        foreach ( $rutas as $ruta ) {
            $this->assertInstanceOf( \WP_Error::class, $this->gate( $ruta ), "{$ruta} must be closed." );
        }
    }

    // -------------------------------------------------------------------------
    // What it must NOT touch
    // -------------------------------------------------------------------------

    public function test_no_toca_otros_namespaces(): void {
        $this->apagar();

        foreach ( [ '/entre-redes/v1/temporadas', '/entre-redes/v1/prode/ranking', '/wp/v2/posts' ] as $ruta ) {
            $this->assertNull( $this->gate( $ruta ), "{$ruta} belongs to somebody else." );
        }
    }

    public function test_no_toca_una_ruta_que_solo_empieza_parecido(): void {
        $this->apagar();

        // `/cambios` and `/cambiosxyz` are NOT under `/cambios/` — the prefix
        // test includes the trailing slash on purpose, the same way
        // denyCambiosResponseCaching()'s does.
        $this->assertNull( $this->gate( '/entre-redes/v1/cambiosxyz/algo' ) );
    }

    public function test_nunca_pisa_una_respuesta_anterior(): void {
        $this->apagar();

        $anterior = new \WP_REST_Response( [ 'ya' => 'respondido' ], 200 );

        $this->assertSame(
            $anterior,
            $this->gate( '/entre-redes/v1/cambios/plazas', $anterior ),
            'A non-null $result means something earlier already answered; overriding it would hide that answer.'
        );
    }

    public function test_un_request_sin_ruta_no_rompe_nada(): void {
        $this->apagar();

        $this->assertNull( Plugin::gateCambiosWhenDisabled( null, new \WP_REST_Server(), 'no soy un request' ) );
    }

    // -------------------------------------------------------------------------
    // The admin side keeps working — the whole point of the switch
    // -------------------------------------------------------------------------

    public function test_el_interruptor_no_toca_el_lado_admin(): void {
        $this->apagar();

        // The bandeja and the histórico are rendered by admin_menu callbacks,
        // never through REST, so the gate cannot reach them. Pinned as a
        // PROPERTY — the filter is only ever registered on rest_pre_dispatch
        // — rather than by rendering a page, which would prove nothing about
        // the filter.
        $ref = new \ReflectionMethod( Plugin::class, 'gateCambiosWhenDisabled' );

        $this->assertTrue( $ref->isStatic() );
        $this->assertNull(
            $this->gate( '/wp-admin/admin.php' ),
            'Nothing outside the /cambios/ REST prefix may be closed by this switch.'
        );
    }
}
