<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Approval\Exception;

/**
 * Any ApprovalRequestRepository DB failure that is NOT a duplicate-key
 * rejection (see AlreadyPendingException) — design D10: "Any other error ->
 * 500". Rest\PhotoUploadController maps this to a generic 500 `error_interno`,
 * never leaking the underlying wpdb error to the caller.
 */
final class ApprovalPersistenceException extends \RuntimeException {
}
