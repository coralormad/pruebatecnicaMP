<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use Throwable;
use Twig\Environment;

/**
 * Manejo de errores centralizado: convierte cualquier excepción en la respuesta adecuada.
 *  - Rutas /api/... -> JSON {"error": {...}} (lo que espera el JavaScript).
 *  - Resto (vistas)  -> página HTML con la plantilla error.html.twig.
 * Un error inesperado (500) nunca muestra detalles al cliente salvo con APP_DEBUG=true.
 */
final class ManejadorErrores
{
    public function __construct(
        private readonly ?Environment $twig,
        private readonly bool $debug,
    ) {
    }

    public function responder(Throwable $e, bool $esApi): Response
    {
        [$status, $mensaje, $campos, $cabeceras] = $this->clasificar($e);

        if ($esApi) {
            return JsonResponse::error($status, $mensaje, $campos, $cabeceras);
        }

        return new HtmlResponse($this->paginaDeError($status, $mensaje), $status, $cabeceras);
    }

    /**
     * @return array{int, string, array<string, string>, array<string, string>}
     */
    private function clasificar(Throwable $e): array
    {
        return match (true) {
            $e instanceof HttpException       => [$e->getStatus(), $e->getMessage(), $e->getFields(), $e->getHeaders()],
            $e instanceof ValidationException => [422, $e->getMessage(), $e->getErrors(), []],
            $e instanceof NotFoundException   => [404, $e->getMessage(), [], []],
            $e instanceof ConflictException   => [409, $e->getMessage(), [], []],
            default                           => [500, $this->mensajeInterno($e), [], []],
        };
    }

    /** Error inesperado (BD caída, bug...): detalle al log, mensaje genérico al cliente. */
    private function mensajeInterno(Throwable $e): string
    {
        $detalle = sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        error_log('[500] ' . $detalle);

        return $this->debug ? $detalle : 'Error interno del servidor.';
    }

    private function paginaDeError(int $status, string $mensaje): string
    {
        if ($this->twig !== null) {
            try {
                return $this->twig->render('error.html.twig', ['status' => $status, 'mensaje' => $mensaje]);
            } catch (Throwable $e) {
                // Si falla la propia plantilla, se sigue con la versión mínima de abajo.
                error_log('[error.html.twig] ' . $e->getMessage());
            }
        }

        // Página mínima sin Twig: el mensaje se escapa a mano.
        return sprintf(
            '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><title>Error %d</title><h1>Error %d</h1><p>%s</p></html>',
            $status,
            $status,
            htmlspecialchars($mensaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
