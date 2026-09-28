<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

/**
 * The single entry point every process-owner-facing admin surface in this
 * plugin (BandejaPage, AdminMenu) asks to answer one question: "is the
 * currently logged-in WP user allowed to manage cambios de jugadores?" —
 * the admin-side equivalent of Capitania\CapitanAuthorizer for the captain
 * side of this plugin.
 *
 * *** WHY A CAPABILITY, NOT current_user_can( 'manage_options' ) ***
 * `manage_options` means "full site administrator" — every other admin page
 * in this codebase (see entre-redes-prode's Admin\SettingsPage /
 * Admin\RegistryPage) gates on it directly, because today the only person
 * who touches those screens IS the site administrator. This plugin's process
 * owner is a DIFFERENT role in the real organization (the person who runs the
 * weekly cambios de jugadores cycle), who today happens to also be an
 * administrator — but the day the league wants to hand this screen to
 * someone else on the comisión WITHOUT making them a full site admin, the fix
 * must be "grant them one capability", never "redesign every permission
 * check in this plugin". `CAPABILITY` is granted to the `administrator` role
 * at plugin activation (see entre-redes-cambios.php) precisely so today's
 * behavior is unchanged while that door stays open.
 *
 * *** WHY THIS CLASS EXISTS AT ALL (INSTEAD OF current_user_can() INLINE) ***
 * Concentrating the check here — instead of every handler in BandejaPage
 * calling `current_user_can( 'gestionar_cambios' )` directly — means the
 * capability name is spelled ONCE. A typo in a scattered call site would
 * silently fail closed (WordPress treats an unknown capability as "nobody
 * has it"), which is the safe direction to fail in, but still the wrong kind
 * of bug to have to hunt for across N call sites instead of one.
 */
final class ProcessOwnerAuthorizer {

    /**
     * The capability this plugin defines for its process-owner screens.
     * Granted to `administrator` on activation — see
     * `entre-redes-cambios.php`'s activation hook.
     */
    public const CAPABILITY = 'gestionar_cambios';

    /**
     * True when the currently logged-in WP user may manage cambios de
     * jugadores (see class docblock). Reads WordPress' own session/capability
     * state via `current_user_can()` — this class has no state of its own.
     */
    public function autorizado(): bool {
        return current_user_can( self::CAPABILITY );
    }
}
