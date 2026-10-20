<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Exception\ValidationException;
use App\Http\HttpException;
use App\Validation\ReservaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios del validador: no tocan la base de datos.
 * Comprueban que los datos de fuera se aceptan, normalizan o rechazan
 * con el error y el campo correctos.
 */
final class ReservaValidatorTest extends TestCase
{
    private ReservaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ReservaValidator();
    }

    /** Cuerpo válido de referencia; cada caso cambia solo lo que quiere probar. */
    private static function cuerpoValido(): array
    {
        return [
            'nombre_huesped'     => 'María José Fernández',
            'email_huesped'      => 'mariajose@example.com',
            'nombre_alojamiento' => 'Apartamentos Triana Río',
            'fecha_entrada'      => '2026-12-01',
            'fecha_salida'       => '2026-12-04',
            'importe'            => '350.00',
        ];
    }

    // ---------------------------------------------------------------
    // Creación
    // ---------------------------------------------------------------

    public function testCreacionValidaDevuelveSoloLosCamposPermitidosNormalizados(): void
    {
        $cuerpo = self::cuerpoValido();
        $cuerpo['nombre_huesped'] = '  María José Fernández  ';

        $datos = $this->validator->validarCreacion($cuerpo);

        $this->assertSame('María José Fernández', $datos['nombre_huesped'], 'Se recortan los espacios');
        $this->assertSame('350.00', $datos['importe'], 'El importe sigue siendo texto');
        $this->assertNull($datos['notas'], 'Sin notas -> null');
        $this->assertSame(
            ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe', 'notas'],
            array_keys($datos),
        );
    }

    public function testImporteEnteroSeAceptaComoTexto(): void
    {
        $datos = $this->validator->validarCreacion(['importe' => 120] + self::cuerpoValido());

        $this->assertSame('120', $datos['importe']);
    }

    public function testNotasVaciasSeGuardanComoNull(): void
    {
        $datos = $this->validator->validarCreacion(['notas' => '   '] + self::cuerpoValido());

        $this->assertNull($datos['notas']);
    }

    /**
     * Cada caso: [cambios sobre el cuerpo válido, campo que debe tener error].
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function creacionInvalida(): array
    {
        return [
            'falta el nombre'            => [['nombre_huesped' => null], 'nombre_huesped'],
            'nombre solo espacios'       => [['nombre_huesped' => '   '], 'nombre_huesped'],
            'nombre que no es texto'     => [['nombre_huesped' => ['Ana']], 'nombre_huesped'],
            'nombre de 121 caracteres'   => [['nombre_huesped' => str_repeat('ñ', 121)], 'nombre_huesped'],
            'nombre con carácter nulo'   => [['nombre_huesped' => "An\0a"], 'nombre_huesped'],
            'email sin arroba'           => [['email_huesped' => 'ana.example.com'], 'email_huesped'],
            'fecha con formato español'  => [['fecha_entrada' => '01/12/2026'], 'fecha_entrada'],
            'fecha imposible'            => [['fecha_entrada' => '2026-02-30'], 'fecha_entrada'],
            'fecha anterior al año 1000' => [['fecha_entrada' => '0999-12-31'], 'fecha_entrada'],
            'salida igual a entrada'     => [['fecha_salida' => '2026-12-01'], 'fecha_salida'],
            'salida antes de entrada'    => [['fecha_salida' => '2026-11-30'], 'fecha_salida'],
            'falta el importe'           => [['importe' => null], 'importe'],
            'importe cero'               => [['importe' => '0.00'], 'importe'],
            'importe negativo'           => [['importe' => '-10'], 'importe'],
            'importe con tres decimales' => [['importe' => '10.999'], 'importe'],
            'importe con coma'           => [['importe' => '10,50'], 'importe'],
            'importe demasiado grande'   => [['importe' => '123456789'], 'importe'],
            'importe booleano'           => [['importe' => true], 'importe'],
            'importe decimal JSON'       => [['importe' => 120.5], 'importe'],
            'notas que no son texto'     => [['notas' => 5], 'notas'],
            'notas de 1001 caracteres'   => [['notas' => str_repeat('a', 1001)], 'notas'],
            'intento de fijar el estado' => [['estado' => 'confirmada'], 'estado'],
            'intento de fijar el id'     => [['id' => 99], 'id'],
        ];
    }

    #[DataProvider('creacionInvalida')]
    public function testCreacionInvalidaLanzaErrorEnElCampo(array $cambios, string $campo): void
    {
        $cuerpo = array_merge(self::cuerpoValido(), $cambios);

        try {
            $this->validator->validarCreacion($cuerpo);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->getErrors());
        }
    }

    public function testCuerpoVacioDevuelveTodosLosErroresALaVez(): void
    {
        try {
            $this->validator->validarCreacion([]);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            // El cliente recibe todos los errores en una sola respuesta, no de uno en uno.
            $this->assertSame(
                ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe'],
                array_keys($e->getErrors()),
            );
        }
    }

    // ---------------------------------------------------------------
    // Cambio de estado
    // ---------------------------------------------------------------

    public function testCambioDeEstadoDevuelveElEstadoRecortado(): void
    {
        $this->assertSame('cancelada', $this->validator->validarCambioEstado(['estado' => ' cancelada ']));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function cambioEstadoInvalido(): array
    {
        return [
            'sin estado'       => [[]],
            'estado numérico'  => [['estado' => 1]],
            'campo extra'      => [['estado' => 'cancelada', 'importe' => '1']],
        ];
    }

    #[DataProvider('cambioEstadoInvalido')]
    public function testCambioDeEstadoInvalidoLanzaValidationException(array $cuerpo): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validarCambioEstado($cuerpo);
    }

    // ---------------------------------------------------------------
    // Filtros
    // ---------------------------------------------------------------

    public function testFiltrosValidosSeRecortanYLosVaciosSeIgnoran(): void
    {
        $filtros = $this->validator->validarFiltros([
            'estado' => 'pendiente',
            'nombre' => '  Fernández ',
            'desde'  => '2026-11-01',
            'hasta'  => '',
        ]);

        $this->assertSame(['estado' => 'pendiente', 'nombre' => 'Fernández', 'desde' => '2026-11-01'], $filtros);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function filtrosInvalidos(): array
    {
        return [
            'estado desconocido' => [['estado' => 'borrada'], 'estado'],
            'filtro desconocido' => [['orden' => 'id'], 'orden'],
            'valor en array'     => [['estado' => ['pendiente']], 'estado'],
            'fecha mal formada'  => [['desde' => '2026-13-01'], 'desde'],
            'desde después de hasta' => [['desde' => '2026-12-10', 'hasta' => '2026-12-01'], 'hasta'],
            'UTF-8 inválido'     => [['nombre' => "\xFF"], 'nombre'],
        ];
    }

    #[DataProvider('filtrosInvalidos')]
    public function testFiltrosInvalidosDan400ConElCampo(array $query, string $campo): void
    {
        try {
            $this->validator->validarFiltros($query);
            $this->fail('Se esperaba HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertArrayHasKey($campo, $e->getFields());
        }
    }

    // ---------------------------------------------------------------
    // Id de la ruta
    // ---------------------------------------------------------------

    public function testIdValidoSeConvierteEnEntero(): void
    {
        $this->assertSame(42, $this->validator->validarId('42'));
    }

    /** @return array<string, array{string}> */
    public static function idsInvalidos(): array
    {
        return [
            'cero'                 => ['0'],
            'ceros a la izquierda' => ['007'],
            'negativo'             => ['-1'],
            'texto'                => ['abc'],
            'inyección'            => ['1 OR 1=1'],
            'demasiado largo'      => ['9999999999999999999'],
        ];
    }

    #[DataProvider('idsInvalidos')]
    public function testIdInvalidoDa400(string $id): void
    {
        try {
            $this->validator->validarId($id);
            $this->fail('Se esperaba HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatus());
        }
    }
}
