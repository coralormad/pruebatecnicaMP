<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;

/**
 * Endpoints JSON de reservas.
 * Solo traduce HTTP <-> servicio: lee la petición, valida, llama al servicio y responde.
 * No contiene SQL ni reglas de negocio. Los errores salen como excepciones
 * y los convierte en respuesta el manejador central (public/index.php).
 */
final class ReservaApiController
{
    public function __construct(
        private readonly ReservaService $service,
        private readonly ReservaValidator $validator,
    ) {
    }

    /** GET /api/reservas?estado=&nombre=&fecha_entrada=&desde=&hasta= */
    public function listar(Request $request): JsonResponse
    {
        $filtros = $this->validator->validarFiltros($request->getQuery());

        return JsonResponse::data($this->service->listar($filtros));
    }

    /**
     * GET /api/reservas/{id}
     *
     * @param array<string, string> $params
     */
    public function detalle(Request $request, array $params): JsonResponse
    {
        $id = $this->validator->validarId($params['id']);

        return JsonResponse::data($this->service->obtener($id));
    }

    /** POST /api/reservas -> 201 */
    public function crear(Request $request): JsonResponse
    {
        $datos = $this->validator->validarCreacion($request->getJsonBody());

        return JsonResponse::data($this->service->crear($datos), 201);
    }

    /**
     * PATCH /api/reservas/{id}/estado  body: {"estado": "confirmada" | "cancelada"}
     *
     * @param array<string, string> $params
     */
    public function cambiarEstado(Request $request, array $params): JsonResponse
    {
        // Primero el id: si no es válido, no tiene sentido leer el cuerpo.
        $id = $this->validator->validarId($params['id']);
        $estado = $this->validator->validarCambioEstado($request->getJsonBody());

        return JsonResponse::data($this->service->cambiarEstado($id, $estado));
    }
}
