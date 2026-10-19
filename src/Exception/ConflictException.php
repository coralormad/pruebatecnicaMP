<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * La operación no es posible en el estado actual del recurso (→ HTTP 409).
 * Ejemplo: cancelar una reserva que ya está cancelada.
 */
final class ConflictException extends RuntimeException
{
}
