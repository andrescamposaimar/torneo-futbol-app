<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Photo;

use EntreRedes\Credencial\Photo\Exception\InsufficientMemoryException;
use EntreRedes\Credencial\Photo\MemoryGuard;
use PHPUnit\Framework\TestCase;

/**
 * MemoryGuard — design D9: "Pre-decode memory guard -> 500
 * insufficient_memory". Same injectable-reader convention as
 * Migrations\MigrationRunner::checkRuntimeLimits() (task 2a.6: "memory-guard
 * 500 via a fake limit provider") — every scenario here is driven by fake
 * `ini_get('memory_limit')` / `memory_get_usage()` readers, never the real
 * PHP process's own memory, so the test suite never depends on how much
 * memory happens to be available where it runs.
 */
class MemoryGuardTest extends TestCase {

    private MemoryGuard $guard;

    protected function setUp(): void {
        $this->guard = new MemoryGuard();
    }

    public function test_passes_when_the_estimated_decode_comfortably_fits(): void {
        $this->guard->ensureEnoughMemoryFor(
            400,
            300,
            static fn (): string => '512M',
            static fn (): int => 1 * 1024 * 1024
        );

        $this->addToAssertionCount( 1 ); // no exception thrown
    }

    public function test_throws_when_a_low_memory_limit_cannot_fit_even_a_small_image(): void {
        $this->expectException( InsufficientMemoryException::class );

        $this->guard->ensureEnoughMemoryFor(
            400,
            300,
            static fn (): string => '16M', // a real hosting floor well below the plugin's needs
            static fn (): int => 15 * 1024 * 1024 // already using nearly all of it
        );
    }

    public function test_throws_when_the_image_is_large_even_under_a_generous_limit(): void {
        $this->expectException( InsufficientMemoryException::class );

        $this->guard->ensureEnoughMemoryFor(
            6000,
            6000, // 36 MP — comfortably over the plugin's own 12 MP validator ceiling,
                  // used here purely to prove the guard reacts to genuinely large dimensions
            static fn (): string => '128M',
            static fn (): int => 0
        );
    }

    public function test_unlimited_memory_limit_always_passes(): void {
        $this->guard->ensureEnoughMemoryFor(
            6000,
            6000,
            static fn (): string => '-1',
            static fn (): int => 0
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_defaults_to_the_real_ini_get_and_memory_get_usage_when_no_reader_is_injected(): void {
        // A tiny image against the real PHP CLI test runner's own memory
        // limit must always pass — proves the default callables are wired,
        // without asserting anything about the host's actual memory.
        $this->guard->ensureEnoughMemoryFor( 300, 300 );

        $this->addToAssertionCount( 1 );
    }
}
