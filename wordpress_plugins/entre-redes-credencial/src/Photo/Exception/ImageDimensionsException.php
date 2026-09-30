<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/**
 * Design D9: 422 `image_dimensions` — outside [300x300, 6000 max side,
 * 12 MP max] (PhotoValidator::MIN_WIDTH/MIN_HEIGHT/MAX_SIDE/MAX_MEGAPIXELS).
 */
final class ImageDimensionsException extends \RuntimeException {
}
