<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Los datos tienen el formato correcto pero no cumplen las reglas (→ HTTP 422).
 * Lleva los errores campo por campo para que el cliente pueda mostrarlos.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors campo => mensaje
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Los datos enviados no son válidos.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
