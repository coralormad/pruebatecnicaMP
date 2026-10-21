/**
 * Piezas de interfaz compartidas por el listado y el detalle.
 * Regla: los datos del usuario se insertan SIEMPRE con textContent, nunca con innerHTML,
 * así un nombre como "<img onerror=...>" se muestra como texto y no se ejecuta.
 */

import { capitalizar } from './formato.js';

// Marca la página como "con JavaScript": el CSS muestra entonces los controles que lo necesitan.
document.documentElement.classList.add('js');

/**
 * Muestra un aviso en la zona de mensajes (role="status": los lectores de pantalla lo leen).
 * @param {'info'|'exito'|'error'} tipo
 */
export function avisar(zona, texto, tipo = 'info') {
    zona.textContent = texto;
    zona.className = `aviso aviso--${tipo}`;
    zona.hidden = false;
}

export function ocultarAviso(zona) {
    zona.hidden = true;
    zona.textContent = '';
}

/** Igual que templates/reservas/_estado.html.twig */
export function crearEtiquetaEstado(estado) {
    const etiqueta = document.createElement('span');
    etiqueta.className = `estado estado--${estado}`;
    etiqueta.textContent = capitalizar(estado);

    return etiqueta;
}

/** Desactiva un botón mientras dura una petición (evita el doble envío). */
export function ocupado(boton, estaOcupado) {
    boton.disabled = estaOcupado;
    boton.setAttribute('aria-busy', String(estaOcupado));
}

export function confirmarCancelacion(nombre) {
    return window.confirm(`¿Cancelar la reserva de ${nombre}? Una reserva cancelada no se puede reactivar.`);
}
