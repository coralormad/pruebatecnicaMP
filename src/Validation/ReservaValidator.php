<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;
use App\Http\HttpException;
use DateTimeImmutable;

/**
 * Valida y normaliza los datos que llegan de fuera antes de que toquen el servicio.
 *
 * - Cuerpo con datos que incumplen las reglas -> ValidationException (422).
 * - Petición mal formada (id, filtros)        -> HttpException 400.
 * Longitudes alineadas con database/reservas.sql.
 */
final class ReservaValidator
{
    public const ESTADOS = ['pendiente', 'confirmada', 'cancelada'];

    /** Campos de texto que se pueden enviar al crear => longitud máxima (VARCHAR del SQL). */
    private const CAMPOS_TEXTO = [
        'nombre_huesped'     => 120,
        'email_huesped'      => 254,
        'nombre_alojamiento' => 150,
        'fecha_entrada'      => 10,
        'fecha_salida'       => 10,
    ];

    private const NOTAS_MAX = 1000;

    /** Lista cerrada de campos admitidos en POST (evita la asignación masiva). */
    private const CAMPOS_CREACION = [
        'nombre_huesped', 'email_huesped', 'nombre_alojamiento',
        'fecha_entrada', 'fecha_salida', 'importe', 'notas',
    ];

    private const FILTROS = ['estado', 'nombre', 'fecha_entrada', 'desde', 'hasta'];

    /** DECIMAL(10,2): hasta 8 dígitos enteros y 2 decimales. */
    private const IMPORTE_REGEX = '/^\d{1,8}(\.\d{1,2})?$/D';

    /** Fecha mínima que MySQL garantiza para el tipo DATE. */
    private const FECHA_MINIMA = '1000-01-01';

    /**
     * @param array<string, mixed> $body cuerpo JSON ya decodificado
     * @return array{nombre_huesped: string, email_huesped: string, nombre_alojamiento: string,
     *               fecha_entrada: string, fecha_salida: string, importe: string, notas: ?string}
     * @throws ValidationException
     */
    public function validarCreacion(array $body): array
    {
        $errores = $this->camposNoPermitidos($body, self::CAMPOS_CREACION);
        $datos = [];

        // 1. Textos obligatorios: tipo string, no vacíos, longitud y sin caracteres de control.
        foreach (self::CAMPOS_TEXTO as $campo => $max) {
            $valor = $body[$campo] ?? null;

            if ($valor === null) {
                $errores[$campo] = 'Campo obligatorio.';
                continue;
            }
            if (!is_string($valor)) {
                $errores[$campo] = 'Debe ser un texto.';
                continue;
            }

            $valor = trim($valor);

            if ($valor === '') {
                $errores[$campo] = 'Campo obligatorio.';
            } elseif (mb_strlen($valor) > $max) {
                $errores[$campo] = "No puede superar {$max} caracteres.";
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $valor) === 1) {
                $errores[$campo] = 'Contiene caracteres no permitidos.';
            } else {
                $datos[$campo] = $valor;
            }
        }

        // 2. Formatos específicos (solo si el campo pasó la comprobación básica).
        if (isset($datos['email_huesped']) && filter_var($datos['email_huesped'], FILTER_VALIDATE_EMAIL) === false) {
            $errores['email_huesped'] = 'El email no tiene un formato válido.';
            unset($datos['email_huesped']);
        }

        foreach (['fecha_entrada', 'fecha_salida'] as $campo) {
            if (isset($datos[$campo]) && !self::esFechaValida($datos[$campo])) {
                $errores[$campo] = 'Debe ser una fecha real con formato AAAA-MM-DD.';
                unset($datos[$campo]);
            }
        }

        // Con formato AAAA-MM-DD, comparar strings equivale a comparar fechas.
        if (isset($datos['fecha_entrada'], $datos['fecha_salida']) && $datos['fecha_salida'] <= $datos['fecha_entrada']) {
            $errores['fecha_salida'] = 'Debe ser posterior a la fecha de entrada.';
        }

        // 3. Importe: texto o entero, validado como texto (nunca pasa por float).
        $importe = $this->normalizarImporte($body['importe'] ?? null, $errores);

        // 4. Notas: opcionales; string o null.
        $notas = $this->normalizarNotas($body['notas'] ?? null, array_key_exists('notas', $body), $errores);

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        return [
            'nombre_huesped'     => $datos['nombre_huesped'],
            'email_huesped'      => $datos['email_huesped'],
            'nombre_alojamiento' => $datos['nombre_alojamiento'],
            'fecha_entrada'      => $datos['fecha_entrada'],
            'fecha_salida'       => $datos['fecha_salida'],
            'importe'            => $importe,
            'notas'              => $notas,
        ];
    }

    /**
     * Cuerpo de PATCH /api/reservas/{id}/estado: {"estado": "..."}.
     * Aquí solo se comprueba la forma; qué estados son destino válido lo decide el servicio.
     *
     * @param array<string, mixed> $body
     * @throws ValidationException
     */
    public function validarCambioEstado(array $body): string
    {
        $errores = $this->camposNoPermitidos($body, ['estado']);
        $estado = $body['estado'] ?? null;

        if ($estado === null) {
            $errores['estado'] = 'Campo obligatorio.';
        } elseif (!is_string($estado)) {
            $errores['estado'] = 'Debe ser un texto.';
        }

        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        return trim($estado);
    }

    /**
     * Filtros de GET /api/reservas. Un parámetro vacío se trata como ausente
     * (los formularios envían los campos vacíos).
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     * @throws HttpException 400
     */
    public function validarFiltros(array $query): array
    {
        $errores = [];
        $filtros = [];

        foreach ($query as $clave => $valor) {
            $clave = (string) $clave;

            if (!in_array($clave, self::FILTROS, true)) {
                $errores[$clave] = 'Filtro no permitido.';
                continue;
            }
            if (!is_string($valor)) {          // ?estado[]=x llega como array
                $errores[$clave] = 'Debe ser un único valor.';
                continue;
            }
            if (!mb_check_encoding($valor, 'UTF-8')) {
                $errores[$clave] = 'Codificación no válida.';
                continue;
            }

            $valor = trim($valor);
            if ($valor !== '') {
                $filtros[$clave] = $valor;
            }
        }

        if (isset($filtros['estado']) && !in_array($filtros['estado'], self::ESTADOS, true)) {
            $errores['estado'] = 'Debe ser pendiente, confirmada o cancelada.';
        }

        if (isset($filtros['nombre']) && mb_strlen($filtros['nombre']) > self::CAMPOS_TEXTO['nombre_huesped']) {
            $errores['nombre'] = 'Demasiado largo.';
        }

        foreach (['fecha_entrada', 'desde', 'hasta'] as $campo) {
            if (isset($filtros[$campo]) && !self::esFechaValida($filtros[$campo])) {
                $errores[$campo] = 'Debe ser una fecha real con formato AAAA-MM-DD.';
            }
        }

        if (!isset($errores['desde']) && !isset($errores['hasta'])
            && isset($filtros['desde'], $filtros['hasta']) && $filtros['desde'] > $filtros['hasta']
        ) {
            $errores['hasta'] = 'Debe ser igual o posterior a "desde".';
        }

        if ($errores !== []) {
            throw new HttpException(400, 'Los filtros no son válidos.', $errores);
        }

        return $filtros;
    }

    /**
     * Id de la ruta: entero positivo sin ceros a la izquierda y que quepa en un int.
     *
     * @throws HttpException 400
     */
    public function validarId(string $id): int
    {
        if (preg_match('/^[1-9]\d{0,17}$/D', $id) !== 1) {
            throw new HttpException(400, 'El identificador no es válido.');
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $permitidos
     * @return array<string, string>
     */
    private function camposNoPermitidos(array $body, array $permitidos): array
    {
        $errores = [];
        foreach (array_keys($body) as $campo) {
            if (!in_array((string) $campo, $permitidos, true)) {
                $errores[(string) $campo] = 'Campo no permitido.';
            }
        }

        return $errores;
    }

    /** @param array<string, string> $errores */
    private function normalizarImporte(mixed $valor, array &$errores): ?string
    {
        if ($valor === null) {
            $errores['importe'] = 'Campo obligatorio.';
            return null;
        }

        // Un número con decimales llega de json_decode como float, y convertir un float
        // a texto puede redondearlo. Para no perder precisión se pide como texto.
        if (is_float($valor)) {
            $errores['importe'] = 'Envía el importe como texto, por ejemplo "120.50".';
            return null;
        }

        // Solo texto o entero. Los booleanos (true), arrays y objetos se rechazan.
        if (!is_string($valor) && !is_int($valor)) {
            $errores['importe'] = 'Debe ser un texto numérico, por ejemplo "120.50".';
            return null;
        }

        $texto = trim((string) $valor);

        if (preg_match(self::IMPORTE_REGEX, $texto) !== 1) {
            $errores['importe'] = 'Formato no válido: hasta 8 enteros y 2 decimales, con punto (ej. 120.50).';
            return null;
        }

        if (preg_match('/[1-9]/', $texto) !== 1) {
            $errores['importe'] = 'Debe ser mayor que 0.';
            return null;
        }

        return $texto;
    }

    /** @param array<string, string> $errores */
    private function normalizarNotas(mixed $valor, bool $enviado, array &$errores): ?string
    {
        if (!$enviado || $valor === null) {
            return null;
        }
        if (!is_string($valor)) {
            $errores['notas'] = 'Debe ser un texto.';
            return null;
        }

        $valor = trim($valor);

        if (mb_strlen($valor) > self::NOTAS_MAX) {
            $errores['notas'] = 'No puede superar ' . self::NOTAS_MAX . ' caracteres.';
            return null;
        }
        // Se permiten saltos de línea y tabuladores; el resto de caracteres de control, no.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $valor) === 1) {
            $errores['notas'] = 'Contiene caracteres no permitidos.';
            return null;
        }

        return $valor === '' ? null : $valor;
    }

    /**
     * Fecha real en formato estricto AAAA-MM-DD (rechaza 2026-02-30)
     * y dentro del rango que MySQL garantiza para DATE.
     */
    private static function esFechaValida(string $valor): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $valor) !== 1) {
            return false;
        }

        // El límite superior (9999-12-31) ya lo impone el formato de 4 dígitos.
        if ($valor < self::FECHA_MINIMA) {
            return false;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha !== false && $fecha->format('Y-m-d') === $valor;
    }
}
