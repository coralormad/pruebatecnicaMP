<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Respuesta HTML (páginas renderizadas con Twig).
 */
final class HtmlResponse implements Response
{
    /**
     * Content-Security-Policy: el navegador solo ejecuta scripts y estilos servidos
     * desde nuestro propio dominio. Aunque se colara HTML malicioso, un <script>
     * en línea o un onerror="..." no se ejecutarían (segunda barrera frente a XSS).
     */
    private const CSP = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** @param array<string, string> $headers Cabeceras extra, p. ej. Allow en un 405 */
    public function __construct(
        private readonly string $html,
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: ' . self::CSP);
        header('Referrer-Policy: same-origin');

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->html;
    }
}
