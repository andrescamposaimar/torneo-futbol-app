<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * Thrown by TestableTitleEditorPage::terminateAfterRedirect() in place of a
 * real `exit;` — the only way to drive a handlePost() success path through
 * its real public entry point instead of Reflection, since a bare `exit;`
 * would terminate the PHPUnit process itself rather than just the request.
 */
final class RedirectTerminatedException extends \RuntimeException {
}
