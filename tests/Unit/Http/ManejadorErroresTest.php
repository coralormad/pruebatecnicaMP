<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\HtmlResponse;
use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Http\ManejadorErrores;
use App\View\TwigFactory;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * El manejador central traduce cada excepción al código HTTP correcto,
 * en JSON para la API y en HTML para las vistas, sin filtrar detalles internos.
 */
final class ManejadorErroresTest extends TestCase
{
    private string $logOriginal;

    protected function setUp(): void
    {
        // Los 500 se registran con error_log(): se desvían a un archivo temporal para no ensuciar la salida.
        $this->logOriginal = (string) ini_get('error_log');
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'phpunit-log'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->logOriginal);
    }

    private function manejador(bool $debug = false): ManejadorErrores
    {
        return new ManejadorErrores(TwigFactory::create(__DIR__ . '/../../../templates'), $debug);
    }

    /** @return array<string, array{Throwable, int}> */
    public static function excepciones(): array
    {
        return [
            'HttpException 405'   => [new HttpException(405, 'Método no permitido.', [], ['Allow' => 'GET']), 405],
            'ValidationException' => [new ValidationException(['email_huesped' => 'No válido.']), 422],
            'NotFoundException'   => [new NotFoundException('La reserva no existe.'), 404],
            'ConflictException'   => [new ConflictException('La reserva ya está cancelada.'), 409],
            'error inesperado'    => [new PDOException('SQLSTATE[HY000] Access denied for user'), 500],
        ];
    }

    #[DataProvider('excepciones')]
    public function testCadaExcepcionTieneSuCodigoEnJsonYEnHtml(Throwable $e, int $status): void
    {
        $json = $this->manejador()->responder($e, true);
        $html = $this->manejador()->responder($e, false);

        $this->assertInstanceOf(JsonResponse::class, $json);
        $this->assertInstanceOf(HtmlResponse::class, $html);
        $this->assertSame($status, $json->getStatus());
        $this->assertSame($status, $html->getStatus());
    }

    public function testLosErroresDeValidacionIncluyenLosCampos(): void
    {
        $respuesta = $this->manejador()->responder(new ValidationException(['importe' => 'Obligatorio.']), true);

        $this->assertEquals((object) ['importe' => 'Obligatorio.'], $respuesta->getPayload()['error']['fields']);
    }

    public function testLosCamposSonUnObjetoJsonAunqueLaClaveSeaNumerica(): void
    {
        $respuesta = $this->manejador()->responder(new ValidationException(['0' => 'Campo no permitido.']), true);

        $this->assertSame(
            '{"error":{"message":"Los datos enviados no son válidos.","fields":{"0":"Campo no permitido."}}}',
            json_encode($respuesta->getPayload(), JSON_UNESCAPED_UNICODE)
        );
    }

    public function testUnErrorInesperadoNoMuestraDetallesInternos(): void
    {
        $e = new PDOException('SQLSTATE[HY000] Access denied for user misterplan_app');

        $json = $this->manejador()->responder($e, true);
        $html = $this->manejador()->responder($e, false);

        $this->assertSame('Error interno del servidor.', $json->getPayload()['error']['message']);
        $this->assertStringNotContainsString('misterplan_app', $html->getHtml());
        $this->assertStringNotContainsString('PDOException', $html->getHtml());
    }

    public function testConDebugActivoSeMuestraElDetalle(): void
    {
        $respuesta = $this->manejador(debug: true)->responder(new PDOException('fallo de prueba'), true);

        $this->assertStringContainsString('PDOException: fallo de prueba', $respuesta->getPayload()['error']['message']);
    }

    public function testSinTwigSeDevuelveUnaPaginaMinimaEscapada(): void
    {
        $respuesta = (new ManejadorErrores(null, false))
            ->responder(new NotFoundException('<b>no existe</b>'), false);

        $this->assertSame(404, $respuesta->getStatus());
        $this->assertStringContainsString('&lt;b&gt;no existe&lt;/b&gt;', $respuesta->getHtml());
    }
}
