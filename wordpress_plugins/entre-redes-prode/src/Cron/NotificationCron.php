<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

/**
 * Cron handler: sends FCM push notifications for Prode events.
 * Full implementation: PR-10.
 */
class NotificationCron {

    public static function runLockApproaching(): void {
        // Stub — implemented in PR-10.
        do_action( 'prode_notification_lock_approaching_ran' );
    }

    public static function runNuevaFecha(): void {
        // Stub — implemented in PR-10.
        do_action( 'prode_notification_nueva_fecha_ran' );
    }

    public static function runFechaCerrada(): void {
        // Stub — implemented in PR-10.
        do_action( 'prode_notification_fecha_cerrada_ran' );
    }
}
