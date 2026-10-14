<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\SettingsWriter;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * The case that shipped broken in 0.1.25 and the ones around it.
 *
 * `wpdb::get_var()` returns `null` for an EMPTY STRING, not only for a
 * missing row — core, verbatim:
 *
 *     return ( isset( $values[ $x ] ) && '' !== $values[ $x ] ) ? $values[ $x ] : null;
 *
 * `SettingsWriter` decides UPDATE-vs-INSERT from "is there a row", so
 * reading an empty value as "no row" sent an INSERT at a row that existed
 * and hit the primary key. It broke exactly one setting in production —
 * `notificacion_nueva_destino`, the only one seeded empty — and 1059 tests
 * passed over it, because the shim returned `''` where core returns `null`.
 *
 * These tests only mean something with that shim faithful. If somebody
 * "simplifies" `get_var()` back, they come back green while the bug returns.
 */
class SettingsWriterTest extends TestCase {

    private SettingsWriter $writer;
    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        global $wpdb;

        InitialSchema::up();
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_settings" );
        InitialSchema::up();

        $this->eventLog = new InMemoryEventLog();
        $this->writer   = new SettingsWriter( $wpdb, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_settings" );
    }

    /** THE regression. */
    public function test_escribe_sobre_una_fila_cuyo_valor_guardado_esta_vacio(): void {
        $this->sembrar( 'una_clave_vacia', '' );

        $resultado = $this->writer->guardarMuchos( [ 'una_clave_vacia' => 'ahora tiene valor' ], '2026-10-14 12:00:00' );

        $this->assertSame( [], $resultado['fallidas'], 'An empty stored value is a ROW, not the absence of one.' );
        $this->assertSame( [ 'una_clave_vacia' ], $resultado['escritas'] );
        $this->assertSame( 'ahora tiene valor', $this->leer( 'una_clave_vacia' ) );
    }

    public function test_escribe_sobre_una_fila_con_valor(): void {
        $this->sembrar( 'una_clave', 'viejo' );

        $resultado = $this->writer->guardarMuchos( [ 'una_clave' => 'nuevo' ], '2026-10-14 12:00:00' );

        $this->assertSame( [], $resultado['fallidas'] );
        $this->assertSame( 'nuevo', $this->leer( 'una_clave' ) );
    }

    public function test_inserta_una_clave_que_no_existia(): void {
        $resultado = $this->writer->guardarMuchos( [ 'clave_nueva' => 'valor' ], '2026-10-14 12:00:00' );

        $this->assertSame( [], $resultado['fallidas'] );
        $this->assertSame( 'valor', $this->leer( 'clave_nueva' ) );
    }

    /**
     * And the mirror of the regression: vaciar una fila que tenía valor.
     * The comparison must not treat the new `''` as "nothing to do".
     */
    public function test_puede_vaciar_una_fila_que_tenia_valor(): void {
        $this->sembrar( 'una_clave', 'algo' );

        $resultado = $this->writer->guardarMuchos( [ 'una_clave' => '' ], '2026-10-14 12:00:00' );

        $this->assertSame( [], $resultado['fallidas'] );
        $this->assertSame( [ 'una_clave' ], $resultado['escritas'] );
        $this->assertSame( '', $this->leer( 'una_clave' ) );
    }

    public function test_no_reescribe_una_fila_vacia_con_el_mismo_vacio(): void {
        $this->sembrar( 'una_clave_vacia', '' );

        $resultado = $this->writer->guardarMuchos( [ 'una_clave_vacia' => '' ], '2026-10-14 12:00:00' );

        $this->assertSame( [], $resultado['escritas'], 'Unchanged is unchanged, empty or not.' );
        $this->assertFalse( $this->eventLog->has( 'settings.modificado' ) );
    }

    public function test_un_cambio_registra_el_anterior_vacio_como_vacio_no_como_nulo(): void {
        $this->sembrar( 'una_clave_vacia', '' );

        $this->writer->guardarMuchos( [ 'una_clave_vacia' => 'x' ], '2026-10-14 12:00:00' );

        $evento = $this->eventLog->last();

        $this->assertSame( 'settings.modificado', $evento['evento'] );
        $this->assertSame( '', $evento['contexto']['anterior'], 'The audit trail must say it WAS empty, not that it was absent.' );
    }

    private function sembrar( string $clave, string $valor ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_settings',
            [ 'setting_key' => $clave, 'setting_value' => $valor, 'updated_at' => '2026-01-01 00:00:00' ]
        );
    }

    /** Reads with get_row for the same reason SettingsWriter does. */
    private function leer( string $clave ): ?string {
        global $wpdb;

        $fila = $wpdb->get_row(
            $wpdb->prepare( "SELECT setting_value FROM {$wpdb->prefix}cambios_settings WHERE setting_key = %s", $clave ),
            ARRAY_A
        );

        return is_array( $fila ) ? (string) $fila['setting_value'] : null;
    }
}
