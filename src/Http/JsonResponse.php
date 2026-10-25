<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Respuesta JSON con formato homogéneo:
 *   éxito -> {"data": ...}
 *   error -> {"error": {"message": "...", "fields": {...}}}
 */
final class JsonResponse implements Response
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly array $payload,
        private readonly int $status,
        private readonly array $headers = [],
    ) {
    }

    public static function data(mixed $data, int $status = 200): self
    {
        return new self(['data' => $data], $status);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $message, array $fields = [], array $headers = []): self
    {
        $error = ['message' => $message];
        if ($fields !== []) {
            // Siempre objeto JSON: con claves numéricas (p. ej. ?0=x) PHP lo codificaría como lista.
            $error['fields'] = (object) $fields;
        }

        return new self(['error' => $error], $status, $headers);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function send(): void
    {
        // Se codifica antes de enviar cabeceras: si falla, el manejador central aún puede responder 500.
        $json = json_encode(
            $this->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $json;
    }
}
