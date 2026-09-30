<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/**
 * Thrown by a MediaWriter when it cannot create or mutate WordPress media —
 * design D11 step (b): "A non-fatal insert failure deletes the file just
 * written." Approval\ApprovalReviewService catches this (via \Throwable) and
 * treats it as a step failure: the approval request stays pending, nothing
 * is published, and the event is logged (`approve.step_failed`).
 */
final class MediaWriteException extends \RuntimeException {
}
