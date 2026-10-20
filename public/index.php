<?php

declare(strict_types=1);

/**
 * Front controller: único punto de entrada PHP de la aplicación.
 * 1. Configura PHP para no mostrar errores.
 * 2. Construye las dependencias y registra las rutas.
 * 3. Despacha la petición.
 * 4. Convierte cualquier excepción en una respuesta JSON (manejo de errores centralizado).
 */

use App\Controller\ReservaApiController;
use App\Database;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ReservaRepository;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;

// Servidor de desarrollo (php -S): los archivos estáticos existentes (CSS, JS) se sirven tal cual.
if (PHP_SAPI === 'cli-server') {
    $archivo = __DIR__ . parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_file($archivo)) {
        return false;
    }
}

// Nunca se muestran errores en la salida; se registran en el log del servidor.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header_remove('X-Powered-By'); // no revelar la versión de PHP

// Warnings y notices se convierten en excepciones: acaban en un 500 controlado, no en HTML suelto.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$debug = false;

try {
    $config = Database::loadConfig($raiz . '/.env');
    $debug = strtolower($config['APP_DEBUG'] ?? 'false') === 'true';

    $pdo = Database::connect($config);
    $api = new ReservaApiController(
        new ReservaService($pdo, new ReservaRepository($pdo)),
        new ReservaValidator(),
    );

    $router = new Router();
    $router->get('/api/reservas', static fn (Request $r): JsonResponse => $api->listar($r));
    $router->post('/api/reservas', static fn (Request $r): JsonResponse => $api->crear($r));
    $router->get('/api/reservas/{id}', static fn (Request $r, array $p): JsonResponse => $api->detalle($r, $p));
    $router->patch('/api/reservas/{id}/estado', static fn (Request $r, array $p): JsonResponse => $api->cambiarEstado($r, $p));

    $response = $router->dispatch(Request::fromGlobals());
} catch (HttpException $e) {
    $response = JsonResponse::error($e->getStatus(), $e->getMessage(), $e->getFields(), $e->getHeaders());
} catch (ValidationException $e) {
    $response = JsonResponse::error(422, $e->getMessage(), $e->getErrors());
} catch (NotFoundException $e) {
    $response = JsonResponse::error(404, $e->getMessage());
} catch (ConflictException $e) {
    $response = JsonResponse::error(409, $e->getMessage());
} catch (Throwable $e) {
    // Cualquier otro error (BD caída, bug...): detalle al log, mensaje genérico al cliente.
    error_log(sprintf('[%s] %s en %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    $mensaje = $debug
        ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine())
        : 'Error interno del servidor.';
    $response = JsonResponse::error(500, $mensaje);
}

try {
    $response->send();
} catch (Throwable $e) {
    // Último recurso: si ni siquiera se puede codificar la respuesta.
    error_log('[send] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo '{"error":{"message":"Error interno del servidor."}}';
}
