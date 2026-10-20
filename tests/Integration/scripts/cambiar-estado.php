<?php

declare(strict_types=1);

/**
 * Script auxiliar del test de concurrencia: simula una SEGUNDA petición
 * que intenta cambiar el estado de una reserva desde otro proceso
 * (con su propia conexión a la BD).
 *
 * Uso: php cambiar-estado.php <ruta .env.testing> <id> <estado>
 * Imprime una sola línea: "ok:<estado final>", "conflicto" o "error:<clase>".
 */

use App\Database;
use App\Exception\ConflictException;
use App\Repository\ReservaRepository;
use App\Service\ReservaService;

require __DIR__ . '/../../../vendor/autoload.php';

[, $envFile, $id, $estado] = $argv;

try {
    $pdo = Database::fromEnv($envFile);
    $service = new ReservaService($pdo, new ReservaRepository($pdo));

    echo 'ok:' . $service->cambiarEstado((int) $id, $estado)['estado'];
} catch (ConflictException) {
    echo 'conflicto';
} catch (Throwable $e) {
    echo 'error:' . $e::class;
}
