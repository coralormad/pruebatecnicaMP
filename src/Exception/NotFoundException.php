<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * El recurso solicitado no existe (→ HTTP 404).
 */
final class NotFoundException extends RuntimeException
{
}
