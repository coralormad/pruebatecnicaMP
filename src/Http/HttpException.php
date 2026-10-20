<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Error propio de la capa HTTP (400, 405, 413, 415...).
 * Las excepciones de dominio (Validation, NotFound, Conflict) no saben nada de HTTP (D16);
 * esta sí, y el manejador central la convierte directamente en respuesta.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $fields  Errores por campo (opcional)
     * @param array<string, string> $headers Cabeceras extra, p. ej. Allow en un 405
     */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly array $fields = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
