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
            // Todo {{ valor }} se escapa como HTML: <script> se muestra como texto, no se ejecuta.
            'autoescape' => 'html',
            // Una variable mal escrita lanza un error en vez de mostrarse vacía en silencio.
            'strict_variables' => true,
            // Sin caché de plantillas compiladas: menos piezas para una prueba técnica.
            // En producción se activaría con una carpeta escribible fuera de public/.
            'cache' => false,
        ]);

        // |date convierte a esta zona horaria al mostrar.
        $twig->getExtension(CoreExtension::class)->setTimezone(self::ZONA_HORARIA);

        return $twig;
    }
}
