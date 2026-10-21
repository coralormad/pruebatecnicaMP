<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Renderiza las plantillas reales con datos de prueba (sin BD) y comprueba
 * el escape de HTML (XSS), los formatos españoles y la zona horaria.
 */
final class PlantillasTest extends TestCase
{
    private const XSS = '<img src=x onerror=alert(1)>';

    private Environment $twig;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC'); // igual que public/index.php
        $this->twig = TwigFactory::create(__DIR__ . '/../../../templates');
    }

    /** @return array<string, mixed> */
    private static function reserva(array $cambios = []): array
    {
        return array_merge([
            'id'                 => 7,
            'nombre_huesped'     => 'María José Fernández',
            'email_huesped'      => 'mariajose@example.com',
            'nombre_alojamiento' => 'Apartamentos Triana Río',
            'fecha_entrada'      => '2026-11-06',
            'fecha_salida'       => '2026-11-09',
            'estado'             => 'confirmada',
            'importe'            => '1234.50',
            'notas'              => null,
            'fecha_creacion'     => '2026-10-01 08:15:00', // UTC
            'eventos'            => [
                ['id' => 1, 'tipo' => 'creacion', 'descripcion' => 'Reserva creada en estado pendiente', 'fecha_creacion' => '2026-10-01 08:15:00'],
                ['id' => 2, 'tipo' => 'confirmacion', 'descripcion' => 'Estado cambiado de pendiente a confirmada', 'fecha_creacion' => '2026-12-01 09:00:00'],
            ],
        ], $cambios);
    }

    private function listado(array $reservas, array $filtros = []): string
    {
        return $this->twig->render('reservas/listado.html.twig', [
            'reservas' => $reservas,
            'filtros'  => $filtros,
            'estados'  => ['pendiente', 'confirmada', 'cancelada'],
        ]);
    }

    public function testElListadoHeredaLaPlantillaBase(): void
    {
        $html = $this->listado([self::reserva()]);

        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim(preg_replace('/\{#.*?#\}/s', '', $html)));
        $this->assertStringContainsString('<title>Listado · Reservas de alojamiento</title>', $html);
        $this->assertStringContainsString('href="/reservas/7"', $html);
    }

    public function testFormateaFechasEImporteEnEspanol(): void
    {
        $html = $this->listado([self::reserva()]);

        $this->assertStringContainsString('06/11/2026', $html);
        $this->assertStringContainsString('1.234,50 €', $html);
    }

    public function testElListadoVacioMuestraUnMensaje(): void
    {
        $this->assertStringContainsString('No hay reservas con estos filtros.', $this->listado([]));
    }

    public function testLosFiltrosAplicadosSeMantienenEnElFormulario(): void
    {
        $html = $this->listado([], ['estado' => 'cancelada', 'nombre' => 'Lucía']);

        $this->assertMatchesRegularExpression('/<option value="cancelada"\s+selected>/', $html);
        $this->assertStringContainsString('value="Lucía"', $html);
    }

    public function testElHtmlDelUsuarioSeEscapaEnElListado(): void
    {
        $html = $this->listado([self::reserva(['nombre_huesped' => self::XSS])]);

        $this->assertStringNotContainsString(self::XSS, $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testElHtmlDelUsuarioSeEscapaEnElDetalleYEnElFiltro(): void
    {
        $detalle = $this->twig->render('reservas/detalle.html.twig', [
            'reserva' => self::reserva(['notas' => self::XSS, 'nombre_alojamiento' => self::XSS]),
        ]);
        // Intento de salir del atributo value="..." del buscador.
        $listado = $this->listado([], ['nombre' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString(self::XSS, $detalle);
        $this->assertStringNotContainsString('<script>alert(1)', $listado);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $listado);
    }

    public function testElHistorialSeMuestraEnHoraDeMadrid(): void
    {
        $html = $this->twig->render('reservas/detalle.html.twig', ['reserva' => self::reserva()]);

        // 08:15 UTC en octubre (horario de verano) = 10:15 en Madrid.
        $this->assertStringContainsString('01/10/2026 10:15', $html);
        // 09:00 UTC en diciembre (horario de invierno) = 10:00 en Madrid.
        $this->assertStringContainsString('01/12/2026 10:00', $html);
        $this->assertStringContainsString('Confirmada', $html);
        $this->assertStringContainsString('Sin notas', $html);
    }

    public function testNoHayJavaScriptEnLineaEnLasPlantillas(): void
    {
        $paginas = [
            $this->listado([self::reserva()]),
            $this->twig->render('reservas/detalle.html.twig', ['reserva' => self::reserva()]),
        ];

        foreach ($paginas as $html) {
            // Solo <script src="...">: la CSP (default-src 'self') bloquearía cualquier script en línea.
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
            // Ni manejadores en atributos (onclick="...", onsubmit="...").
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
            $this->assertMatchesRegularExpression('#<script type="module" src="/assets/js/[a-z]+\.js"></script>#', $html);
        }
    }

    public function testLaReservaCanceladaNoTieneBotonDeCancelar(): void
    {
        $listado = $this->listado([self::reserva(['estado' => 'cancelada'])]);
        $detalle = $this->twig->render('reservas/detalle.html.twig', ['reserva' => self::reserva(['estado' => 'cancelada'])]);

        // Solo queda el botón del <template> (molde para el JS), no uno en la fila.
        $this->assertSame(1, substr_count($listado, 'data-accion="cancelar"'));
        $this->assertStringNotContainsString('data-accion="cancelar"', $detalle);
    }

    public function testLaPaginaDeErrorEscapaElMensaje(): void
    {
        $html = $this->twig->render('error.html.twig', ['status' => 404, 'mensaje' => self::XSS]);

        $this->assertStringContainsString('No encontrado', $html);
        $this->assertStringNotContainsString(self::XSS, $html);
    }
}
