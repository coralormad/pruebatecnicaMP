/**
 * Cliente de la API: el único sitio donde se llama a fetch().
 * Convierte cualquier fallo (red caída, HTTP 4xx/5xx, respuesta que no es JSON)
 * en un ApiError con un mensaje que se puede mostrar al usuario.
 */

export class ApiError extends Error {
    /**
     * @param {string} message mensaje para el usuario
     * @param {number} status  código HTTP (0 = no hubo respuesta: error de red)
     * @param {Object<string, string>} fields errores por campo (422 y 400 de filtros)
     */
    constructor(message, status = 0, fields = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.fields = fields;
    }
}

async function pedir(url, { method = 'GET', body } = {}) {
    const opciones = { method, headers: { Accept: 'application/json' } };

    if (body !== undefined) {
        // La API exige este Content-Type en las escrituras (si no, responde 415).
        opciones.headers['Content-Type'] = 'application/json';
        opciones.body = JSON.stringify(body);
    }

    let respuesta;
    try {
        respuesta = await fetch(url, opciones);
    } catch {
        // fetch solo falla así cuando no llega respuesta: sin conexión, servidor apagado...
        throw new ApiError('No se pudo conectar con el servidor. Comprueba tu conexión e inténtalo de nuevo.');
    }

    let json;
    try {
        json = await respuesta.json();
    } catch {
        // Por ejemplo, una página de error HTML de un proxy en lugar de nuestro JSON.
        throw new ApiError(`El servidor devolvió una respuesta no válida (HTTP ${respuesta.status}).`, respuesta.status);
    }

    // fetch NO lanza error con 400 o 500: hay que comprobar response.ok a mano.
    if (!respuesta.ok) {
        const error = json?.error ?? {};
        throw new ApiError(error.message ?? `Error HTTP ${respuesta.status}.`, respuesta.status, error.fields ?? {});
    }

    return json.data;
}

export const api = {
    /** @param {Object<string, string>} filtros */
    listar: (filtros) => pedir(`/api/reservas?${new URLSearchParams(filtros)}`),

    crear: (datos) => pedir('/api/reservas', { method: 'POST', body: datos }),

    cambiarEstado: (id, estado) =>
        pedir(`/api/reservas/${encodeURIComponent(id)}/estado`, { method: 'PATCH', body: { estado } }),
};

/** Texto para el usuario a partir de cualquier error (incluidos los de programación). */
export function mensajeDeError(error) {
    if (!(error instanceof ApiError)) {
        console.error(error);
        return 'Ha ocurrido un error inesperado.';
    }

    const campos = Object.values(error.fields);

    return campos.length > 0 ? `${error.message} ${campos.join(' ')}` : error.message;
}
