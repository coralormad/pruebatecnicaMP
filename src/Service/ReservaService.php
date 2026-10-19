<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\ReservaRepository;
use PDO;
use Throwable;

/**
 * Lógica de negocio de las reservas.
 *
 * - Decide qué cambios de estado están permitidos.
 * - Garantiza que cada cambio y su evento de historial se guardan juntos (transacción).
 * No sabe nada de HTTP: comunica los problemas lanzando excepciones de dominio.
 */
final class ReservaService
{
    /**
     * Máquina de estados: desde cada estado, a cuáles se puede pasar.
     * "cancelada" es un estado final.
     */
    private const TRANSICIONES = [
        'pendiente' => ['confirmada', 'cancelada'],
        'confirmada' => ['cancelada'],
        'cancelada' => [],
    ];

    /**
     * Estado destino => tipo de evento que se registra en el historial.
     */
    private const EVENTO_POR_ESTADO = [
        'confirmada' => 'confirmacion',
        'cancelada' => 'cancelacion',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ReservaRepository $repository,
    ) {
    }

    /**
     * @param array<string, string> $filtros filtros ya validados
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros): array
    {
        return $this->repository->findAll($filtros);
    }

    /**
     * Devuelve la reserva con su historial.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function obtener(int $id): array
    {
        $reserva = $this->repository->findById($id);

        if ($reserva === null) {
            throw new NotFoundException('La reserva no existe.');
        }

        $reserva['eventos'] = $this->repository->findEventos($id);

        return $reserva;
    }

    /**
     * Crea una reserva (siempre en estado pendiente) y su evento de creación.
     *
     * @param array{nombre_huesped: string, email_huesped: string, nombre_alojamiento: string,
     *              fecha_entrada: string, fecha_salida: string, importe: string, notas: ?string} $datos
     *        datos ya validados
     * @return array<string, mixed> la reserva creada, con su historial
     */
    public function crear(array $datos): array
    {
        $id = $this->enTransaccion(function () use ($datos): int {
            $id = $this->repository->insert($datos);
            $this->repository->insertEvento($id, 'creacion', 'Reserva creada en estado pendiente');

            return $id;
        });

        return $this->obtener($id);
    }

    /**
     * Cambia el estado de una reserva respetando las transiciones permitidas.
     *
     * @return array<string, mixed> la reserva actualizada, con su historial
     * @throws ValidationException si el estado destino no es válido
     * @throws NotFoundException   si la reserva no existe
     * @throws ConflictException   si la transición no está permitida
     */
    public function cambiarEstado(int $id, string $nuevoEstado): array
    {
        if (!isset(self::EVENTO_POR_ESTADO[$nuevoEstado])) {
            throw new ValidationException(['estado' => 'El estado debe ser "confirmada" o "cancelada".']);
        }

        $this->enTransaccion(function () use ($id, $nuevoEstado): void {
            // FOR UPDATE: bloquea la fila para que dos peticiones simultáneas
            // no puedan cambiar el estado a la vez.
            $reserva = $this->repository->findByIdForUpdate($id);

            if ($reserva === null) {
                throw new NotFoundException('La reserva no existe.');
            }

            $estadoActual = $reserva['estado'];

            if ($estadoActual === $nuevoEstado) {
                throw new ConflictException("La reserva ya está {$estadoActual}.");
            }

            if (!in_array($nuevoEstado, self::TRANSICIONES[$estadoActual], true)) {
                throw new ConflictException("No se puede pasar una reserva de {$estadoActual} a {$nuevoEstado}.");
            }

            $this->repository->updateEstado($id, $nuevoEstado);
            $this->repository->insertEvento(
                $id,
                self::EVENTO_POR_ESTADO[$nuevoEstado],
                "Estado cambiado de {$estadoActual} a {$nuevoEstado}",
            );
        });

        return $this->obtener($id);
    }

    /**
     * Ejecuta $operacion dentro de una transacción:
     * si todo va bien, confirma (commit); si algo falla, deshace todo (rollback) y relanza el error.
     *
     * @template T
     * @param callable(): T $operacion
     * @return T
     */
    private function enTransaccion(callable $operacion): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $resultado = $operacion();
            $this->pdo->commit();

            return $resultado;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
