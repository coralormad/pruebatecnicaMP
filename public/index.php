<?php

declare(strict_types=1);

/**
 * Front controller: configura PHP, registra las rutas y despacha la petición.
 */

use App\Controller\ReservaApiController;
use App\Controller\ReservaViewController;
use App\Database;
use App\Http\JsonResponse;
use App\Http\ManejadorErrores;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ReservaRepository;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;
use App\View\TwigFactory;

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

// PHP trabaja en UTC, igual que la conexión a la BD (Database::connect). Twig convierte a Madrid al mostrar.
date_default_timezone_set('UTC');

// Warnings y notices se convierten en excepciones: acaban en un 500 controlado, no en HTML suelto.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$request = Request::fromGlobals();
$esApi = $request->getPath() === '/api' || str_starts_with($request->getPath(), '/api/');
$debug = false;
$twig = null;

try {
    $config = Database::loadConfig($raiz . '/.env');
    $debug = strtolower($config['APP_DEBUG'] ?? 'false') === 'true';
    $twig = TwigFactory::create($raiz . '/templates');
    $validador = new ReservaValidator();

    // Conexión perezosa: el servicio (y la conexión a la BD) se crea la primera vez
    // que una ruta lo necesita. Así una ruta inexistente da 404 aunque la BD esté caída.
    $servicio = null;
    $obtenerServicio = static function () use ($config, &$servicio): ReservaService {
        if ($servicio === null) {
            $pdo = Database::connect($config);
            $servicio = new ReservaService($pdo, new ReservaRepository($pdo));
        }

        return $servicio;
    };
    $api = static fn (): ReservaApiController => new ReservaApiController($obtenerServicio(), $validador);
    $vistas = static fn (): ReservaViewController => new ReservaViewController($obtenerServicio(), $validador, $twig);

    $router = new Router();

    // Vistas HTML (Twig)
    $router->get('/', static fn (Request $r) => $vistas()->listado($r));
    $router->get('/reservas', static fn (Request $r) => $vistas()->listado($r));
    $router->get('/reservas/{id}', static fn (Request $r, array $p) => $vistas()->detalle($r, $p));

    // API JSON
    $router->get('/api/reservas', static fn (Request $r): JsonResponse => $api()->listar($r));
    $router->post('/api/reservas', static fn (Request $r): JsonResponse => $api()->crear($r));
    $router->get('/api/reservas/{id}', static fn (Request $r, array $p): JsonResponse => $api()->detalle($r, $p));
    $router->patch('/api/reservas/{id}/estado', static fn (Request $r, array $p): JsonResponse => $api()->cambiarEstado($r, $p));

    $response = $router->dispatch($request);
} catch (Throwable $e) {
    $response = (new ManejadorErrores($twig, $debug))->responder($e, $esApi);
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
