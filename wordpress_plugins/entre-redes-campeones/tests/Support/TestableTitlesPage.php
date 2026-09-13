<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

use EntreRedes\Campeones\Admin\TitlesPage;

/**
 * TitlesPage with its post-redirect `exit;` replaced by a catchable
 * exception — the TitlesPage counterpart of TestableTitleEditorPage, so a
 * test can drive a full success (or caught-exception) path through the
 * real handlePost() entry point instead of Reflection.
 */
final class TestableTitlesPage extends TitlesPage {
    protected function terminateAfterRedirect(): void {
        throw new RedirectTerminatedException( 'redirect' );
    }
}
