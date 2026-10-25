<?php

declare(strict_types=1);

namespace App\View;

use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Crea el entorno de Twig con una configuración segura y la misma en la app y en los tests.
 */
final class TwigFactory
{
    /** Zona en la que se muestran las fechas con hora (la BD las guarda en UTC). */
    public const ZONA_HORARIA = 'Europe/Madrid';

    public static function create(string $directorioPlantillas): Environment
    {
        $twig = new Environment(new FilesystemLoader($directorioPlantillas), [
            // Autoescape HTML en todo {{ valor }} (XSS).
            'autoescape' => 'html',
            // Error si una variable no existe.
            'strict_variables' => true,
            // Sin caché de plantillas compiladas; en producción se activaría con una carpeta escribible fuera de public/.
            'cache' => false,
        ]);

        $twig->getExtension(CoreExtension::class)->setTimezone(self::ZONA_HORARIA);

        return $twig;
    }
}
