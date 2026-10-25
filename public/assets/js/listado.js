/**
 * Página de listado: filtrar, crear y cancelar reservas sin recargar la página.
 * Twig pinta la carga inicial; a partir de ahí este archivo repinta la tabla con los datos de la API.
 */

import { api, mensajeDeError } from './api.js';
import * as formato from './formato.js';
import { avisar, confirmarCancelacion, crearEtiquetaEstado, ocultarAviso, ocupado } from './ui.js';

const formFiltros = document.querySelector('#form-filtros');
const enlaceLimpiar = document.querySelector('#limpiar-filtros');
const contenedorTabla = document.querySelector('#contenedor-tabla');
const cuerpoTabla = document.querySelector('#cuerpo-tabla');
const plantillaFila = document.querySelector('#plantilla-fila');
const plantillaVacia = document.querySelector('#plantilla-vacia');
const resumen = document.querySelector('#resumen');
const avisos = document.querySelector('#avisos');
const formCrear = document.querySelector('#form-crear');

// ------------------------------------------------------------------
// Pintar la tabla
// ------------------------------------------------------------------

/** Única función que crea una fila: clona el <template> de Twig y rellena los datos con textContent. */
function pintarFila(reserva) {
    const fila = plantillaFila.content.firstElementChild.cloneNode(true);
    const campo = (nombre) => fila.querySelector(`[data-campo="${nombre}"]`);

    campo('nombre_huesped').textContent = reserva.nombre_huesped;
    campo('email_huesped').textContent = reserva.email_huesped;
    campo('nombre_alojamiento').textContent = reserva.nombre_alojamiento;
    campo('fecha_entrada').textContent = formato.fecha(reserva.fecha_entrada);
    campo('fecha_salida').textContent = formato.fecha(reserva.fecha_salida);
    campo('importe').textContent = formato.importe(reserva.importe);
    campo('estado').replaceChildren(crearEtiquetaEstado(reserva.estado));
    campo('detalle').href = `/reservas/${reserva.id}`;

    const botonCancelar = fila.querySelector('[data-accion="cancelar"]');
    if (reserva.estado === 'cancelada') {
        botonCancelar.remove();
    } else {
        botonCancelar.dataset.id = reserva.id;
        botonCancelar.dataset.nombre = reserva.nombre_huesped;
    }

    return fila;
}

function pintarTabla(reservas) {
    const filas = reservas.length > 0
        ? reservas.map(pintarFila)
        : [plantillaVacia.content.firstElementChild.cloneNode(true)];

    cuerpoTabla.replaceChildren(...filas);
    resumen.textContent = `${reservas.length} ${reservas.length === 1 ? 'reserva' : 'reservas'}`;
}

// ------------------------------------------------------------------
// Filtrar
// ------------------------------------------------------------------

function filtrosDelFormulario() {
    const filtros = {};
    for (const [clave, valor] of new FormData(formFiltros)) {
        const texto = String(valor).trim();
        if (texto !== '') {
            filtros[clave] = texto;
        }
    }

    return filtros;
}

// Si se filtra varias veces seguidas, solo se pinta la respuesta de la última petición.
let ultimaPeticion = 0;

/** @returns {Promise<boolean>} true si la tabla se actualizó */
async function cargarReservas() {
    const filtros = filtrosDelFormulario();

    if (filtros.desde && filtros.hasta && filtros.desde > filtros.hasta) {
        avisar(avisos, '«Hasta» debe ser igual o posterior a «Desde».', 'error');
        return false;
    }

    const numero = ++ultimaPeticion;
    contenedorTabla.setAttribute('aria-busy', 'true');
    avisar(avisos, 'Cargando reservas…');

    try {
        const reservas = await api.listar(filtros);
        if (numero !== ultimaPeticion) {
            return false;
        }

        pintarTabla(reservas);
        ocultarAviso(avisos);

        // La URL refleja los filtros: recargar o compartir el enlace mantiene el resultado.
        const consulta = new URLSearchParams(filtros).toString();
        history.replaceState(null, '', consulta ? `?${consulta}` : location.pathname);

        return true;
    } catch (error) {
        if (numero === ultimaPeticion) {
            avisar(avisos, mensajeDeError(error), 'error');
        }

        return false;
    } finally {
        if (numero === ultimaPeticion) {
            contenedorTabla.removeAttribute('aria-busy');
        }
    }
}

/**
 * Se llama después de crear o cancelar, cuando la API ya ha guardado el cambio.
 * Si la lista no se puede repintar (filtros no válidos, fallo de red), se avisa igualmente
 * de que el cambio está guardado: así nadie repite la operación pensando que falló.
 */
async function repintarTrasGuardar(mensajeExito) {
    const repintada = await cargarReservas();
    const aviso = repintada
        ? mensajeExito
        : `${mensajeExito} No se pudo actualizar la lista: revisa los filtros y vuelve a filtrar.`;
    avisar(avisos, aviso, 'exito');
}

formFiltros.addEventListener('submit', (evento) => {
    evento.preventDefault(); // sin JS, el formulario GET recarga la página (sigue funcionando)
    cargarReservas();
});

enlaceLimpiar.addEventListener('click', (evento) => {
    evento.preventDefault();
    formFiltros.reset();
    // reset() vuelve a los valores con los que se pintó la página: se vacían a mano.
    formFiltros.querySelectorAll('input, select').forEach((control) => { control.value = ''; });
    cargarReservas();
});

// ------------------------------------------------------------------
// Cancelar (delegación de eventos: sirve también para las filas pintadas después)
// ------------------------------------------------------------------

cuerpoTabla.addEventListener('click', async (evento) => {
    const boton = evento.target.closest('[data-accion="cancelar"]');
    if (!boton || !confirmarCancelacion(boton.dataset.nombre)) {
        return;
    }

    ocupado(boton, true);
    try {
        await api.cambiarEstado(boton.dataset.id, 'cancelada');
    } catch (error) {
        avisar(avisos, mensajeDeError(error), 'error');
        ocupado(boton, false);
        return;
    }

    await repintarTrasGuardar(`Reserva de ${boton.dataset.nombre} cancelada.`);
});

// ------------------------------------------------------------------
// Crear
// ------------------------------------------------------------------

const CAMPOS = ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe', 'notas'];
const IMPORTE = /^\d{1,8}(\.\d{1,2})?$/; // la misma regla que el servidor (DECIMAL(10,2))
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MAX_NOCHES = 365;   // las mismas reglas de fechas que ReservaValidator
const MAX_ANIOS_ANTELACION = 2;

/** "AAAA-MM-DD" -> número de días desde 1970 (en UTC, sin horas ni cambios de hora). */
function dias(iso) {
    const [anio, mes, dia] = iso.split('-').map(Number);

    return Date.UTC(anio, mes - 1, dia) / 86_400_000;
}

function limiteDeEntrada() {
    const hoy = new Date();
    const limite = new Date(Date.UTC(hoy.getUTCFullYear() + MAX_ANIOS_ANTELACION, hoy.getUTCMonth(), hoy.getUTCDate()));

    return limite.toISOString().slice(0, 10);
}

function datosDelFormulario() {
    const datos = {};
    for (const campo of CAMPOS) {
        datos[campo] = formCrear.elements[campo].value.trim();
    }
    datos.importe = datos.importe.replace(',', '.'); // se acepta "120,50" al escribir
    if (datos.notas === '') {
        delete datos.notas; // opcional
    }

    return datos;
}

/**
 * Validación en el navegador: solo para avisar antes y ahorrar una petición.
 * La validación que manda es la del servidor (el cliente se puede saltar).
 */
function validar(datos) {
    const errores = {};
    for (const campo of ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe']) {
        if (!datos[campo]) {
            errores[campo] = 'Campo obligatorio.';
        }
    }
    if (datos.email_huesped && !EMAIL.test(datos.email_huesped)) {
        errores.email_huesped = 'El email no tiene un formato válido.';
    }
    if (datos.fecha_entrada && datos.fecha_salida) {
        if (datos.fecha_salida <= datos.fecha_entrada) {
            errores.fecha_salida = 'Debe ser posterior a la fecha de entrada.';
        } else if (datos.fecha_entrada > limiteDeEntrada()) {
            errores.fecha_entrada = `No puede ser posterior a ${formato.fecha(limiteDeEntrada())} (2 años desde hoy).`;
        } else if (dias(datos.fecha_salida) - dias(datos.fecha_entrada) > MAX_NOCHES) {
            errores.fecha_salida = `La estancia no puede superar ${MAX_NOCHES} noches.`;
        }
    }
    if (datos.importe && (!IMPORTE.test(datos.importe) || !/[1-9]/.test(datos.importe))) {
        errores.importe = 'Importe mayor que 0, con hasta 2 decimales (ej. 120,50).';
    }

    return errores;
}

function limpiarErrores() {
    for (const campo of CAMPOS) {
        formCrear.elements[campo].removeAttribute('aria-invalid');
        document.querySelector(`#error-${campo}`).textContent = '';
    }
}

/** Pinta los errores junto a cada campo (vengan del navegador o del 422 del servidor). */
function mostrarErrores(errores) {
    let primero = null;
    for (const [campo, mensaje] of Object.entries(errores)) {
        const control = formCrear.elements[campo];
        const zona = document.querySelector(`#error-${campo}`);
        if (!control || !zona) {
            continue; // p. ej. "Campo no permitido": ya aparece en el aviso general
        }
        control.setAttribute('aria-invalid', 'true');
        zona.textContent = mensaje;
        primero ??= control;
    }
    primero?.focus();
}

formCrear.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    limpiarErrores();

    const datos = datosDelFormulario();
    const errores = validar(datos);
    if (Object.keys(errores).length > 0) {
        mostrarErrores(errores);
        avisar(avisos, 'Revisa los campos marcados.', 'error');
        return;
    }

    const boton = formCrear.querySelector('button[type="submit"]');
    ocupado(boton, true);
    avisar(avisos, 'Guardando la reserva…');

    let reserva;
    try {
        reserva = await api.crear(datos);
    } catch (error) {
        if (error.status === 422) {
            mostrarErrores(error.fields);
        }
        avisar(avisos, mensajeDeError(error), 'error');
        return;
    } finally {
        ocupado(boton, false);
    }

    formCrear.reset();
    await repintarTrasGuardar(`Reserva #${reserva.id} de ${reserva.nombre_huesped} creada (pendiente).`);
});
