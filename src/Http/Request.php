<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;
use stdClass;

/**
 * Representa la petición entrante. Encapsula el acceso a $_SERVER, $_GET y php://input
 * para que el resto de la aplicación no toque superglobales.
 */
final class Request
{
    public const MAX_BODY_BYTES = 16384; // 16 KB
    private const JSON_MAX_DEPTH = 32;

    private ?string $rawBody = null;

    /** @param array<string, mixed> $query */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly string $contentType,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? rtrim($path, '/') : '';
        if ($path === '') {
            $path = '/';
        }

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        return new self($method, $path, $_GET, $contentType);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Parámetros de la query sin tratar: pueden ser strings o arrays (?estado[]=x).
     * El validador decide qué es válido.
     *
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * Devuelve el cuerpo JSON como array asociativo de primer nivel.
     *
     * @return array<string, mixed>
     * @throws HttpException 415, 413 o 400
     */
    public function getJsonBody(): array
    {
        if (!$this->isJson()) {
            throw new HttpException(415, 'El cuerpo debe enviarse como application/json.');
        }

        $raw = $this->readRawBody();

        if (trim($raw) === '') {
            throw new HttpException(400, 'El cuerpo de la petición está vacío.');
        }

        try {
            // false = objetos JSON como stdClass, para distinguir {} de []
            $decoded = json_decode($raw, false, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new HttpException(400, 'El cuerpo no es un JSON válido.');
        }

        if (!$decoded instanceof stdClass) {
            throw new HttpException(400, 'El cuerpo debe ser un objeto JSON.');
        }

        // Solo el primer nivel pasa a array; los valores anidados siguen siendo
        // objetos/arrays y el validador los rechazará por tipo.
        return get_object_vars($decoded);
    }

    /** Compara el media type real, ignorando parámetros como "; charset=utf-8". */
    private function isJson(): bool
    {
        $mediaType = strtolower(trim(explode(';', $this->contentType, 2)[0]));

        return $mediaType === 'application/json';
    }

    /** Lee como máximo MAX_BODY_BYTES + 1 bytes: si hay más, el cuerpo es demasiado grande. */
    private function readRawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }

        $stream = fopen('php://input', 'rb');
        $raw = $stream === false ? '' : (string) stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        if ($stream !== false) {
            fclose($stream);
        }

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'El cuerpo de la petición supera el tamaño permitido.');
        }

        return $this->rawBody = $raw;
    }
}
