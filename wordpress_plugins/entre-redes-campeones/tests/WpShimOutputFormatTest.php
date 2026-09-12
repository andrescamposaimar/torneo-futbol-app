<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shim's $output contract for wpdb::get_row() / wpdb::get_results():
 * real WordPress defaults to OBJECT (stdClass rows) and only returns
 * associative arrays when ARRAY_A is passed explicitly. Before this fix the
 * shim ignored $output entirely and always returned arrays — which is
 * exactly why TitleRepository::find()/findByKey() (missing the ARRAY_A
 * argument, feeding TitleRecord::fromRow(array $row) under
 * declare(strict_types=1)) never failed in tests, yet would throw a
 * TypeError on the very first row read against real WordPress.
 */
class WpShimOutputFormatTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    public function test_get_row_defaults_to_object_like_real_wordpress(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert(
            $p . 'campeones_titulo',
            [
                'anio'          => 2020,
                'zona'          => 'A',
                'posicion'      => 'campeon',
                'equipo_nombre' => 'BOCA',
                'created_at'    => '2020-01-01 00:00:00',
                'updated_at'    => '2020-01-01 00:00:00',
            ]
        );

        $row = $wpdb->get_row( "SELECT * FROM {$p}campeones_titulo LIMIT 1" );

        $this->assertInstanceOf(
            \stdClass::class,
            $row,
            'wpdb::get_row() with no explicit $output must default to OBJECT, exactly like real WordPress — a shim that silently hands back an array hides TypeError-on-production bugs like the one in TitleRepository.'
        );
        $this->assertSame( 'BOCA', $row->equipo_nombre );
    }

    public function test_get_row_returns_array_a_only_when_explicitly_requested(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert(
            $p . 'campeones_titulo',
            [
                'anio'          => 2021,
                'zona'          => 'A',
                'posicion'      => 'campeon',
                'equipo_nombre' => 'RIVER',
                'created_at'    => '2021-01-01 00:00:00',
                'updated_at'    => '2021-01-01 00:00:00',
            ]
        );

        $row = $wpdb->get_row( "SELECT * FROM {$p}campeones_titulo LIMIT 1", ARRAY_A );

        $this->assertIsArray( $row );
        $this->assertSame( 'RIVER', $row['equipo_nombre'] );
    }

    public function test_get_results_defaults_to_object_like_real_wordpress(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert(
            $p . 'campeones_titulo',
            [
                'anio'          => 2022,
                'zona'          => 'A',
                'posicion'      => 'campeon',
                'equipo_nombre' => 'INDEPENDIENTE',
                'created_at'    => '2022-01-01 00:00:00',
                'updated_at'    => '2022-01-01 00:00:00',
            ]
        );

        $rows = $wpdb->get_results( "SELECT * FROM {$p}campeones_titulo" );

        $this->assertNotEmpty( $rows );
        $this->assertInstanceOf( \stdClass::class, $rows[0] );
    }

    /**
     * Proves the fixed call sites read usable data end to end: with ARRAY_A
     * passed explicitly (as TitleRepository now does) and the shim honouring
     * it, TitleRecord::fromRow(array $row) receives the array type it is
     * declared to accept.
     */
    public function test_repository_read_returns_usable_data_with_array_a(): void {
        global $wpdb;

        $repository = new TitleRepository( $wpdb );
        $created    = $repository->createOrConflict( 2023, 'A', 'campeon', 'VELEZ' );

        $this->assertNotNull( $created );

        $found = $repository->find( $created->id );
        $this->assertNotNull( $found );
        $this->assertSame( 'VELEZ', $found->equipoNombre );
    }
}
