<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Approval\Exception;

/**
 * Thrown when a player already has a pending approval request of the same
 * type — design D10: "no replacing a pending photo" (CONFIRMED by the user,
 * `credencial/foto-pendiente-sin-reemplazo`). Rest\PhotoUploadController
 * maps this to 409 `already_pending`.
 *
 * This is a DB-level rejection (the `pending_key` UNIQUE index, or its
 * FaultInjectingWpdb-scripted stand-in in tests), never a pre-check —
 * ApprovalRequestRepository::createPendingPhotoRequest() does not
 * SELECT-then-INSERT, which would reopen the exact TOCTOU race that index
 * exists to close.
 */
final class AlreadyPendingException extends \RuntimeException {

    public function __construct( int $playerId ) {
        parent::__construct( "Player {$playerId} already has a pending approval request." );
    }
}
