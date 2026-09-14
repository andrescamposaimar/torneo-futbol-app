<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

use EntreRedes\Campeones\Admin\TitleEditorPage;

/**
 * TitleEditorPage with its post-redirect `exit;` replaced by a catchable
 * exception, so tests can drive a full success path through the real
 * handlePost() entry point (allow-list check, capability check, nonce
 * check, dispatch, PRG redirect) instead of reaching into a private
 * handler via Reflection — which is exactly what let handlePost() ship
 * with no add-row / edit-row form and no CSRF check without the suite
 * noticing (see TitleEditorPageTest).
 */
final class TestableTitleEditorPage extends TitleEditorPage {
    protected function terminateAfterRedirect(): void {
        throw new RedirectTerminatedException( 'redirect' );
    }
}
