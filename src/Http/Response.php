<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Lo único que el front controller necesita de una respuesta: poder enviarla.
 * La implementan JsonResponse (API) y HtmlResponse (vistas Twig).
 */
interface Response
{
    public function getStatus(): int;

    public function send(): void;
}
