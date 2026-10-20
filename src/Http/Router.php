<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * Router mínimo: asocia método + patrón de ruta a un manejador.
 * Patrones con parámetros entre llaves: /api/reservas/{id}/estado
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: Closure}> */
    private array $routes = [];

    public function get(string $pattern, Closure $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, Closure $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, Closure $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function add(string $method, string $pattern, Closure $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => self::compile($pattern),
            'handler' => $handler,
        ];
    }

    /**
     * Ejecuta el manejador de la ruta que coincide y devuelve su resultado.
     *
     * @throws HttpException 404 si ninguna ruta coincide; 405 (con Allow) si la ruta existe con otro método
     */
    public function dispatch(Request $request): mixed
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->getPath(), $matches) !== 1) {
                continue;
            }

            // La ruta existe: se apunta su método aunque no coincida, para un posible 405.
            if ($route['method'] !== $request->getMethod()) {
                $allowed[] = $route['method'];
                continue;
            }

            // Solo los grupos con nombre ({id}), no los índices numéricos de preg_match.
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, $params);
        }

        if ($allowed !== []) {
            throw new HttpException(
                405,
                'Método no permitido para esta ruta.',
                [],
                ['Allow' => implode(', ', array_unique($allowed))]
            );
        }

        throw new HttpException(404, 'Ruta no encontrada.');
    }

    /** Convierte "/api/reservas/{id}" en "#^/api/reservas/(?P<id>[^/]+)$#D". */
    private static function compile(string $pattern): string
    {
        $segments = explode('/', trim($pattern, '/'));

        $parts = array_map(
            static function (string $segment): string {
                if (preg_match('/^\{(\w+)\}$/', $segment, $m) === 1) {
                    return '(?P<' . $m[1] . '>[^/]+)';
                }

                return preg_quote($segment, '#');
            },
            $segments
        );

        return '#^/' . implode('/', $parts) . '$#D';
    }
}
