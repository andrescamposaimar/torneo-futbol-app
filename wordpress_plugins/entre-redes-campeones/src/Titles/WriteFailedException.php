<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Titles;

/**
 * Thrown when a wpdb write (insert/update/delete) to campeones_titulo or
 * campeones_plantel fails at the database level.
 *
 * Distinct on purpose from a `null` return: TitleRepository::createOrConflict()
 * already uses `null` to mean "a record for this key already exists"
 * (REC-7). If a failed INSERT were also reported as `null`, a caller could
 * never tell a legitimate duplicate apart from lost data — this exception is
 * the signal that separates the two.
 */
class WriteFailedException extends \RuntimeException {
}
