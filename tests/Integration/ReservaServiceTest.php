<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\ReservaRepository;
use App\Service\ReservaService;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests de integración: ReservaService + ReservaRepository contra una BD MySQL real
 * (misterplan_test), para comprobar lo que un test unitario no puede:
 * SQL, transacciones, historial y filtros.
 *
 * Cada test empieza con las tablas vacías y crea sus propios datos.
 */
final class ReservaServiceTest extends TestCase
{
    private const ENV_FILE = __DIR__ . '/../../.env.testing';

    private PDO $pdo;
    private ReservaService $service;

    protected function setUp(): void
    {
        if (!is_readable(self::ENV_FILE)) {
            self::markTestSkipped('Falta .env.testing: los tests de integración necesitan la BD misterplan_test.');
        }

        $config = Database::loadConfig(self::ENV_FILE);

        // Protección: estos tests BORRAN datos. Nunca deben apuntar a la BD de desarrollo.
        if (!str_ends_with($config['DB_NAME'], '_test')) {
            self::fail('DB_NAME de .env.testing debe terminar en "_test" (los tests vacían las tablas).');
        }

        $this->pdo = Database::connect($config);
        $this->service = new ReservaService($this->pdo, new ReservaRepository($this->pdo));

        // Primero los eventos: la FK (ON DELETE RESTRICT) impide borrar reservas con historial.
        $this->pdo->exec('DELETE FROM reserva_evento');
        $this->pdo->exec('DELETE FROM reserva');
    }

    /** Datos ya validados, como los que el controlador pasa al servicio. */
    private function crearReserva(array $cambios = []): array
    {
        return $this->service->crear(array_merge([
            'nombre_huesped'     => 'María José Fernández',
            'email_huesped'      => 'mariajose@example.com',
            'nombre_alojamiento' => 'Apartamentos Triana Río',
            'fecha_entrada'      => '2026-11-06',
            'fecha_salida'       => '2026-11-09',
            'importe'            => '285.00',
            'notas'              => null,
        ], $cambios));
    }

    private function contar(string $tabla): int
    {
        // $tabla viene del propio test, nunca del usuario.
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn();
    }

    /** @param array<string, mixed> $reserva */
    private static function tipos(array $reserva): array
    {
        return array_column($reserva['eventos'], 'tipo');
    }

    // ---------------------------------------------------------------
    // Creación
    // ---------------------------------------------------------------

    public function testCrearGuardaLaReservaPendienteConSuEventoDeCreacion(): void
    {
        $reserva = $this->crearReserva(['notas' => 'Llegada tarde']);

        $this->assertIsInt($reserva['id']);
        $this->assertSame('pendiente', $reserva['estado'], 'Toda reserva nueva empieza pendiente');
        $this->assertSame('285.00', $reserva['importe'], 'DECIMAL llega como texto exacto');
        $this->assertSame('Llegada tarde', $reserva['notas']);
        $this->assertNotEmpty($reserva['fecha_creacion'], 'La fecha de creación la pone la BD');
        $this->assertSame(['creacion'], self::tipos($reserva));
    }

    public function testSiFallaElEventoNoSeGuardaLaReserva(): void
    {
        // Se fuerza un fallo real en el segundo INSERT de la transacción
        // renombrando la tabla del historial.
        $this->pdo->exec('RENAME TABLE reserva_evento TO reserva_evento_off');

        try {
            $this->crearReserva();
            $this->fail('Se esperaba PDOException al insertar el evento');
        } catch (PDOException) {
            // Esperado.
        } finally {
            $this->pdo->exec('RENAME TABLE reserva_evento_off TO reserva_evento');
        }

        // Rollback: el INSERT de la reserva también se ha deshecho.
        $this->assertSame(0, $this->contar('reserva'));
        $this->assertFalse($this->pdo->inTransaction(), 'No queda ninguna transacción abierta');
    }

    // ---------------------------------------------------------------
    // Cambios de estado
    // ---------------------------------------------------------------

    /** @return array<string, array{list<string>, string, list<string>}> */
    public static function transicionesValidas(): array
    {
        return [
            'pendiente → confirmada'  => [[], 'confirmada', ['creacion', 'confirmacion']],
            'pendiente → cancelada'   => [[], 'cancelada', ['creacion', 'cancelacion']],
            'confirmada → cancelada'  => [['confirmada'], 'cancelada', ['creacion', 'confirmacion', 'cancelacion']],
        ];
    }

    /**
     * @param list<string> $previos   estados por los que pasa antes
     * @param list<string> $historial tipos de evento esperados, en orden
     */
    #[DataProvider('transicionesValidas')]
    public function testTransicionValidaCambiaElEstadoYAnadeUnEvento(array $previos, string $destino, array $historial): void
    {
        $id = $this->crearReserva()['id'];
        foreach ($previos as $estado) {
            $this->service->cambiarEstado($id, $estado);
        }

        $reserva = $this->service->cambiarEstado($id, $destino);

        $this->assertSame($destino, $reserva['estado']);
        $this->assertSame($historial, self::tipos($reserva));
    }

    /** @return array<string, array{list<string>, string}> */
    public static function transicionesInvalidas(): array
    {
        return [
            'confirmar una confirmada' => [['confirmada'], 'confirmada'],
            'cancelar una cancelada'   => [['cancelada'], 'cancelada'],
            'reabrir una cancelada'    => [['cancelada'], 'confirmada'],
        ];
    }

    /** @param list<string> $previos */
    #[DataProvider('transicionesInvalidas')]
    public function testTransicionInvalidaDa409SinTocarDatos(array $previos, string $destino): void
    {
        $id = $this->crearReserva()['id'];
        foreach ($previos as $estado) {
            $this->service->cambiarEstado($id, $estado);
        }
        $antes = $this->service->obtener($id);

        try {
            $this->service->cambiarEstado($id, $destino);
            $this->fail('Se esperaba ConflictException');
        } catch (ConflictException) {
            // Esperado.
        }

        // Ni el estado ni el historial han cambiado.
        $this->assertEquals($antes, $this->service->obtener($id));
    }

    public function testVolverAPendienteNoEsUnDestinoValido(): void
    {
        $id = $this->crearReserva()['id'];

        $this->expectException(ValidationException::class);

        $this->service->cambiarEstado($id, 'pendiente');
    }

    public function testCambiarEstadoDeReservaInexistenteDa404(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->cambiarEstado(999999, 'cancelada');
    }

    public function testObtenerReservaInexistenteDa404(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->obtener(999999);
    }

    // ---------------------------------------------------------------
    // Filtros (llegan ya validados)
    // ---------------------------------------------------------------

    /** Crea un escenario pequeño y devuelve los ids por clave. */
    private function escenarioFiltros(): array
    {
        $ids = [
            'maria'  => $this->crearReserva()['id'],                                    // 06–09 nov, pendiente
            'javier' => $this->crearReserva([
                'nombre_huesped' => 'Javier Núñez', 'fecha_entrada' => '2026-11-09', 'fecha_salida' => '2026-11-12',
            ])['id'],                                                                   // 09–12 nov
            'lucia'  => $this->crearReserva([
                'nombre_huesped' => 'Lucía Peña', 'fecha_entrada' => '2026-12-01', 'fecha_salida' => '2026-12-03',
            ])['id'],                                                                   // 01–03 dic
            'raro'   => $this->crearReserva(['nombre_huesped' => 'Ana 100% Real'])['id'],   // nombre con %
        ];
        $this->service->cambiarEstado($ids['javier'], 'confirmada');

        return $ids;
    }

    /** @param array<string, string> $filtros */
    private function idsListados(array $filtros): array
    {
        return array_column($this->service->listar($filtros), 'id');
    }

    public function testSinFiltrosDevuelveTodasOrdenadasPorEntrada(): void
    {
        $ids = $this->escenarioFiltros();

        $this->assertSame(
            [$ids['maria'], $ids['raro'], $ids['javier'], $ids['lucia']],
            $this->idsListados([]),
        );
    }

    public function testFiltrosCombinadosEstadoYNombre(): void
    {
        $ids = $this->escenarioFiltros();

        $this->assertSame([$ids['javier']], $this->idsListados(['estado' => 'confirmada', 'nombre' => 'nuñez']));
        $this->assertSame([], $this->idsListados(['estado' => 'pendiente', 'nombre' => 'Javier']));
    }

    public function testBusquedaPorNombreIgnoraTildesYMayusculas(): void
    {
        $ids = $this->escenarioFiltros();

        // utf8mb4_unicode_ci: "fernandez" encuentra "Fernández".
        $this->assertSame([$ids['maria']], $this->idsListados(['nombre' => 'FERNANDEZ']));
    }

    public function testElPorcentajeSeBuscaComoTextoNoComoComodin(): void
    {
        $ids = $this->escenarioFiltros();

        // Sin escapar, "%" coincidiría con todas las reservas.
        $this->assertSame([$ids['raro']], $this->idsListados(['nombre' => '%']));
    }

    public function testRangoPorSolapeElDiaDeSalidaNoCuentaComoOcupado(): void
    {
        $ids = $this->escenarioFiltros();

        // María sale el 09 y Javier entra el 09: solo Javier ocupa la noche del 9 al 10.
        $this->assertSame([$ids['javier']], $this->idsListados(['desde' => '2026-11-09', 'hasta' => '2026-11-09']));

        // Del 07 al 08 solo se solapan las de 06–09 nov (María y "raro").
        $this->assertSame([$ids['maria'], $ids['raro']], $this->idsListados(['desde' => '2026-11-07', 'hasta' => '2026-11-08']));
    }

    public function testFechaDeEntradaExactaCombinadaConRango(): void
    {
        $ids = $this->escenarioFiltros();

        $this->assertSame([$ids['lucia']], $this->idsListados(['fecha_entrada' => '2026-12-01']));
        $this->assertSame([], $this->idsListados(['fecha_entrada' => '2026-12-01', 'hasta' => '2026-11-30']));
    }
}
