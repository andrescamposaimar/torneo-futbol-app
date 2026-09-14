<?php

declare(strict_types=1);

/**
 * Namespaced error_log() overrides used to make "logs on failure" behaviour
 * mutation-testable.
 *
 * PHP resolves an UNQUALIFIED function call by first checking whether a
 * function with that name exists in the CURRENT namespace, and only falls
 * back to the global one if it does not (language.namespaces.rules) — so
 * CacheInvalidator's and the Rest controllers' plain `error_log(...)` calls
 * resolve to the overrides below instead of the real, unloggable global
 * one, with no change to production code required.
 *
 * Every other namespace in this plugin (Titles, Linking, Admin) is
 * untouched here and keeps calling the real global error_log() exactly as
 * it did before — this file only shadows the two namespaces whose
 * "log on cache failure" behaviour (items 2 and 3) needed to become
 * observable in a test.
 */

namespace {
    $GLOBALS['_campeones_test_error_log'] = [];
}

namespace EntreRedes\Campeones\Cache {
    function error_log( string $message, int $message_type = 0, ?string $destination = null, ?string $extra_headers = null ): bool {
        $GLOBALS['_campeones_test_error_log'][] = $message;
        return true;
    }
}

namespace EntreRedes\Campeones\Rest {
    function error_log( string $message, int $message_type = 0, ?string $destination = null, ?string $extra_headers = null ): bool {
        $GLOBALS['_campeones_test_error_log'][] = $message;
        return true;
    }
}
