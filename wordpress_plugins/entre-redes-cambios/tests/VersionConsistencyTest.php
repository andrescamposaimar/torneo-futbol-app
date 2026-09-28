<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards against the exact drift that hit entre-redes-prode: the plugin
 * header's `Version:`, the `ENTRE_REDES_CAMBIOS_VERSION` constant, and
 * readme.txt's `Stable tag:` are three independent places that must always
 * name the same release. Nothing enforces that at edit time — only this test
 * does, by reading all three sources fresh and comparing them.
 */
class VersionConsistencyTest extends TestCase {

    public function test_plugin_header_constant_and_readme_stable_tag_all_match(): void {
        $pluginFile = dirname( __DIR__ ) . '/entre-redes-cambios.php';
        $readmeFile = dirname( __DIR__ ) . '/readme.txt';

        $pluginSource = file_get_contents( $pluginFile );
        $this->assertNotFalse( $pluginSource );

        $this->assertMatchesRegularExpression(
            '/^\s*\*\s*Version:\s*([0-9.]+)/m',
            $pluginSource,
            'Plugin header must declare a Version: line.'
        );
        preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $pluginSource, $headerMatch );
        $headerVersion = $headerMatch[1];

        $this->assertMatchesRegularExpression(
            "/define\\(\\s*'ENTRE_REDES_CAMBIOS_VERSION',\\s*'([0-9.]+)'\\s*\\)/",
            $pluginSource,
            'entre-redes-cambios.php must define ENTRE_REDES_CAMBIOS_VERSION.'
        );
        preg_match( "/define\\(\\s*'ENTRE_REDES_CAMBIOS_VERSION',\\s*'([0-9.]+)'\\s*\\)/", $pluginSource, $constantMatch );
        $constantVersion = $constantMatch[1];

        $readmeSource = file_get_contents( $readmeFile );
        $this->assertNotFalse( $readmeSource );

        $this->assertMatchesRegularExpression(
            '/^Stable tag:\s*([0-9.]+)/m',
            $readmeSource,
            'readme.txt must declare a Stable tag: line.'
        );
        preg_match( '/^Stable tag:\s*([0-9.]+)/m', $readmeSource, $readmeMatch );
        $readmeVersion = $readmeMatch[1];

        $this->assertSame( $headerVersion, $constantVersion, 'Plugin header Version must match ENTRE_REDES_CAMBIOS_VERSION.' );
        $this->assertSame( $headerVersion, $readmeVersion, 'Plugin header Version must match readme.txt Stable tag.' );
    }
}
