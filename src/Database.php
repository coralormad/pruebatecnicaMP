<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Crea la conexión PDO a MySQL/MariaDB a partir del archivo .env.
 *
 * No guarda estado: devuelve una conexión nueva en cada llamada.
 * El front controller la crea una vez por petición y la pasa a quien la necesite.
 */
final class Database
{
    private const REQUIRED_KEYS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /**
     * Atajo: lee el .env y devuelve la conexión.
     */
    public static function fromEnv(string $envFile): PDO
    {
        return self::connect(self::loadConfig($envFile));
    }

    /**
     * Lee y valida el archivo de configuración.
     *
     * @return array<string, string>
     */
    public static function loadConfig(string $envFile): array
    {
        if (!is_readable($envFile)) {
            throw new RuntimeException('No se encuentra el archivo de configuración.');
        }

        // INI_SCANNER_RAW: los valores se leen tal cual. Sin él, PHP convertiría
        // "true", "no" o "null" en booleanos/vacío y podría alterar una contraseña.
        $config = parse_ini_file($envFile, false, INI_SCANNER_RAW);

        if ($config === false) {
            throw new RuntimeException('El archivo de configuración no es válido.');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("Falta la variable de configuración {$key}.");
            }
        }

        return $config;
    }

    /**
     * Abre la conexión con opciones seguras.
     *
     * @param array<string, string> $config
     */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['DB_HOST'],
            (int) $config['DB_PORT'],
            $config['DB_NAME'],
        );

        return new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
            // Cualquier error SQL lanza PDOException en lugar de devolver false en silencio.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Las filas llegan como arrays asociativos: ['id' => 1, ...].
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Consultas preparadas reales en el servidor MySQL, no simuladas por PHP:
            // la consulta y los datos viajan por separado y los enteros llegan como int.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
