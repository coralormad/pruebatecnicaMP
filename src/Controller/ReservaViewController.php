<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HtmlResponse;
use App\Http\Request;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;
use Twig\Environment;

/**
 * Páginas HTML renderizadas en el servidor con Twig.
 * Usa el mismo servicio y el mismo validador que la API: las reglas no se duplican.
 */
final class ReservaViewController
{
    public function __construct(
        private readonly ReservaService $service,
        private readonly ReservaValidator $validator,
        private readonly Environment $twig,
    ) {
    }

    /** GET /reservas?estado=&nombre=&desde=&hasta= (también funciona sin JavaScript) */
    public function listado(Request $request): HtmlResponse
    {
        $filtros = $this->validator->validarFiltros($request->getQuery());

        return new HtmlResponse($this->twig->render('reservas/listado.html.twig', [
            'reservas' => $this->service->listar($filtros),
            'filtros'  => $filtros,
            'estados'  => ReservaValidator::ESTADOS,
        ]));
    }

    /**
     * GET /reservas/{id}
     *
     * @param array<string, string> $params
     */
    public function detalle(Request $request, array $params): HtmlResponse
    {
        $id = $this->validator->validarId($params['id']);

        return new HtmlResponse($this->twig->render('reservas/detalle.html.twig', [
            'reserva' => $this->service->obtener($id),
        ]));
    }
}
