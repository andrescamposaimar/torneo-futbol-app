<?php

declare(strict_types=1);

namespace EntreRedes\Prode;

/**
 * Validates that the entre-redes base plugin is active.
 *
 * If not, this plugin self-deactivates and displays an admin notice so the
 * operator knows exactly what is wrong.
 *
 * ADR-P009: runtime check on `plugins_loaded:11`; no `Requires Plugins:` header
 * for backwards compatibility with WP < 6.5.
 */
final class DependencyCheck {

    public static function ensureActive(): void {
        if ( ! self::isEntreRedesActive() ) {
            add_action( 'admin_notices', [ self::class, 'showMissingDependencyNotice' ] );
            deactivate_plugins( plugin_basename( ENTRE_REDES_PRODE_FILE ) );
            return;
        }

        if ( ! defined( 'PRODE_TENANT_ID' ) || '' === trim( (string) PRODE_TENANT_ID ) ) {
            add_action( 'admin_notices', [ self::class, 'showMissingTenantIdNotice' ] );
            deactivate_plugins( plugin_basename( ENTRE_REDES_PRODE_FILE ) );
            return;
        }
    }

    private static function isEntreRedesActive(): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Attempt by known slug first; fall back to checking for a constant
        // the entre-redes plugin is expected to define.
        return is_plugin_active( 'entre-redes/entre-redes.php' )
            || defined( 'ENTRE_REDES_VERSION' );
    }

    public static function showMissingDependencyNotice(): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Entre Redes — Prode Interno requires the Entre Redes plugin to be installed and active. Please activate Entre Redes first.',
            'entre-redes-prode'
        );
        echo '</p></div>';
    }

    public static function showMissingTenantIdNotice(): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Entre Redes — Prode Interno requires the PRODE_TENANT_ID constant to be defined in wp-config.php. Example: define( \'PRODE_TENANT_ID\', \'marianista\' );',
            'entre-redes-prode'
        );
        echo '</p></div>';
    }
}
