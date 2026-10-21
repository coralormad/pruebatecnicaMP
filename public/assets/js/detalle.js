/**
 * Página de detalle: cancelar la reserva sin recargar y actualizar estado e historial.
 */

import { api, mensajeDeError } from './api.js';
import * as formato from './formato.js';
import { avisar, confirmarCancelacion, crearEtiquetaEstado, ocupado } from './ui.js';

const botonCancelar = document.querySelector('[data-accion="cancelar"]');
const estadoActual = document.querySelector('#estado-actual');
const historial = document.querySelector('#historial');
const plantillaEvento = document.querySelector('#plantilla-evento');
const avisos = document.querySelector('#avisos');

const ETIQUETAS = { creacion: 'Creada', confirmacion: 'Confirmada', cancelacion: 'Cancelada' };

function pintarEvento(evento) {
    const item = plantillaEvento.content.firstElementChild.cloneNode(true);
    const hora = item.querySelector('time');

    item.classList.add(`timeline__evento--${evento.tipo}`);
    hora.dateTime = `${evento.fecha_creacion.replace(' ', 'T')}Z`;
    hora.textContent = formato.fechaHora(evento.fecha_creacion);
    item.querySelector('strong').textContent = ETIQUETAS[evento.tipo] ?? evento.tipo;
    item.querySelector('span').textContent = evento.descripcion;

    return item;
}

// En una reserva ya cancelada no hay botón.
botonCancelar?.addEventListener('click', async () => {
    if (!confirmarCancelacion(botonCancelar.dataset.nombre)) {
        return;
    }

    ocupado(botonCancelar, true);
    avisar(avisos, 'Cancelando la reserva…');

    try {
        const reserva = await api.cambiarEstado(botonCancelar.dataset.id, 'cancelada');

        estadoActual.replaceChildren(crearEtiquetaEstado(reserva.estado));
        historial.replaceChildren(...reserva.eventos.map(pintarEvento));
        botonCancelar.remove();
        avisar(avisos, 'Reserva cancelada.', 'exito');
    } catch (error) {
        avisar(avisos, mensajeDeError(error), 'error');
        ocupado(botonCancelar, false);
    }
});
