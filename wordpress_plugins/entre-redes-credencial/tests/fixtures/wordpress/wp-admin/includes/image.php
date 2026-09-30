<?php

declare(strict_types=1);

/**
 * Test fixture standing in for WordPress core's real
 * wp-admin/includes/image.php — used ONLY by the entre-redes-credencial
 * plugin's PHPUnit suite (tests/Photo/WpMediaWriterTest.php) to prove that
 * WpMediaWriter::ensureMetadataGenerated() genuinely requires this file
 * rather than relying on a pre-defined shim function.
 *
 * tests/wp-shim.php deliberately does NOT define
 * wp_generate_attachment_metadata() — only requiring this file (exactly as
 * WpMediaWriter::ensureMetadataGenerated() does in production) makes it
 * available, mirroring real WordPress's own admin-only bootstrap.
 */

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
    function wp_generate_attachment_metadata( int $attachment_id, string $file ): array {
        // Real WordPress inspects the actual file (dimensions, sizes...); this
        // fixture only needs a non-empty, stable shape — no caller in this
        // plugin reads specific fields out of it.
        return [ 'file' => $file, 'width' => 0, 'height' => 0 ];
    }
}
