<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Acceso a datos de reservas y su historial.
 *
 * Solo contiene SQL con parámetros preparados: ninguna regla de negocio.
 * Recibe datos ya validados por la capa superior.
 */
final class ReservaRepository
{
    private const COLUMNAS_RESERVA = 'id, nombre_huesped, email_huesped, nombre_alojamiento,
        fecha_entrada, fecha_salida, estado, importe, notas, fecha_creacion';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Lista reservas aplicando los filtros recibidos (todos opcionales y combinables).
     *
     * @param array{estado?: string, nombre?: string, fecha_entrada?: string, desde?: string, hasta?: string} $filtros
     * @return list<array<string, mixed>>
     */
    public function findAll(array $filtros): array
    {
        $condiciones = [];
        $parametros = [];

        if (isset($filtros['estado'])) {
            $condiciones[] = 'estado = :estado';
            $parametros['estado'] = $filtros['estado'];
        }

        if (isset($filtros['nombre'])) {
            // ESCAPE '!': % y _ escritos por el usuario se buscan como texto, no como comodines.
            $condiciones[] = "nombre_huesped LIKE :nombre ESCAPE '!'";
            $parametros['nombre'] = '%' . $this->escaparLike($filtros['nombre']) . '%';
        }

        if (isset($filtros['fecha_entrada'])) {
            $condiciones[] = 'fecha_entrada = :fecha_entrada';
            $parametros['fecha_entrada'] = $filtros['fecha_entrada'];
        }

        // Rango desde/hasta: reservas que se solapan con el periodo.
        // El día de salida no cuenta como noche ocupada (fecha_salida > desde).
        if (isset($filtros['hasta'])) {
            $condiciones[] = 'fecha_entrada <= :hasta';
            $parametros['hasta'] = $filtros['hasta'];
        }

        if (isset($filtros['desde'])) {
            $condiciones[] = 'fecha_salida > :desde';
            $parametros['desde'] = $filtros['desde'];
        }

        $sql = 'SELECT ' . self::COLUMNAS_RESERVA . ' FROM reserva';

        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }

        $sql .= ' ORDER BY fecha_entrada ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNAS_RESERVA . ' FROM reserva WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Igual que findById, pero bloquea la fila hasta el final de la transacción.
     * Evita que dos peticiones simultáneas cambien el estado de la misma reserva a la vez.
     * Solo tiene efecto dentro de una transacción.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNAS_RESERVA . ' FROM reserva WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEventos(int $reservaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo, descripcion, fecha_creacion
             FROM reserva_evento
             WHERE reserva_id = :reserva_id
             ORDER BY fecha_creacion ASC, id ASC'
        );
        $stmt->execute(['reserva_id' => $reservaId]);

        return $stmt->fetchAll();
    }

    /**
     * Inserta una reserva. El estado (pendiente) y la fecha de creación los pone la BD.
     *
     * @param array{nombre_huesped: string, email_huesped: string, nombre_alojamiento: string,
     *              fecha_entrada: string, fecha_salida: string, importe: string, notas: ?string} $datos
     */
    public function insert(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reserva
                (nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, fecha_salida, importe, notas)
             VALUES
                (:nombre_huesped, :email_huesped, :nombre_alojamiento, :fecha_entrada, :fecha_salida, :importe, :notas)'
        );

        // Lista cerrada de campos: aunque $datos trajera "estado" o "id", no se usarían.
        $stmt->execute([
            'nombre_huesped' => $datos['nombre_huesped'],
            'email_huesped' => $datos['email_huesped'],
            'nombre_alojamiento' => $datos['nombre_alojamiento'],
            'fecha_entrada' => $datos['fecha_entrada'],
            'fecha_salida' => $datos['fecha_salida'],
            'importe' => $datos['importe'],
            'notas' => $datos['notas'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateEstado(int $id, string $estado): void
    {
        $stmt = $this->pdo->prepare('UPDATE reserva SET estado = :estado WHERE id = :id');
        $stmt->execute(['estado' => $estado, 'id' => $id]);
    }

    public function insertEvento(int $reservaId, string $tipo, string $descripcion): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reserva_evento (reserva_id, tipo, descripcion)
             VALUES (:reserva_id, :tipo, :descripcion)'
        );
        $stmt->execute(['reserva_id' => $reservaId, 'tipo' => $tipo, 'descripcion' => $descripcion]);
    }

    /**
     * Escapa los comodines de LIKE usando "!" como carácter de escape.
     */
    private function escaparLike(string $texto): string
    {
        return strtr($texto, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
