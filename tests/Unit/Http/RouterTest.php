<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios del router: qué manejador se ejecuta y cuándo se responde 404 o 405.
 */
final class RouterTest extends TestCase
{
    private Router $router;

    /** @var array<string, mixed> copia de $_SERVER para restaurarla */
    private array $serverOriginal;

    protected function setUp(): void
    {
        $this->serverOriginal = $_SERVER;

        // Manejadores simples que devuelven qué se ejecutó y con qué parámetros.
        $this->router = new Router();
        $this->router->get('/api/reservas', static fn (): string => 'listar');
        $this->router->post('/api/reservas', static fn (): string => 'crear');
        $this->router->get('/api/reservas/{id}', static fn (Request $r, array $p): string => 'detalle ' . $p['id']);
        $this->router->patch('/api/reservas/{id}/estado', static fn (Request $r, array $p): string => 'estado ' . $p['id']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverOriginal;
    }

    /** Simula una petición: Request se construye a partir de $_SERVER, como en producción. */
    private function peticion(string $metodo, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_SERVER['REQUEST_URI'] = $uri;

        return Request::fromGlobals();
    }

    public function testEjecutaElManejadorSegunMetodoYRuta(): void
    {
        $this->assertSame('listar', $this->router->dispatch($this->peticion('GET', '/api/reservas')));
        $this->assertSame('crear', $this->router->dispatch($this->peticion('POST', '/api/reservas')));
    }

    public function testExtraeElParametroDeLaRuta(): void
    {
        $this->assertSame('detalle 7', $this->router->dispatch($this->peticion('GET', '/api/reservas/7')));
        $this->assertSame('estado 7', $this->router->dispatch($this->peticion('PATCH', '/api/reservas/7/estado')));
    }

    public function testIgnoraLaQueryYLaBarraFinal(): void
    {
        $this->assertSame('listar', $this->router->dispatch($this->peticion('GET', '/api/reservas/?estado=pendiente')));
    }

    public function testRutaInexistenteDa404(): void
    {
        try {
            $this->router->dispatch($this->peticion('GET', '/api/alojamientos'));
            $this->fail('Se esperaba HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatus());
        }
    }

    public function testMetodoNoPermitidoDa405ConCabeceraAllow(): void
    {
        try {
            $this->router->dispatch($this->peticion('DELETE', '/api/reservas'));
            $this->fail('Se esperaba HttpException');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->getStatus());
            // Allow indica qué métodos sí admite la ruta (lo exige HTTP en un 405).
            $this->assertSame(['Allow' => 'GET, POST'], $e->getHeaders());
        }
    }

    public function testIdNoNumericoLlegaAlControladorParaQueDe400(): void
    {
        // El router no valida el id: lo hace ReservaValidator::validarId (400, no 404).
        $this->assertSame('detalle abc', $this->router->dispatch($this->peticion('GET', '/api/reservas/abc')));
    }
}
