/**
 * Formatos españoles, iguales a los de las plantillas Twig.
 * Se trabaja con los textos que devuelve la API, sin pasar por Number:
 * el importe llega como "1234.50" (DECIMAL) y se formatea sin redondeos.
 */

/** "2026-11-06" -> "06/11/2026" */
export function fecha(iso) {
    const [anio, mes, dia] = iso.split('-');

    return `${dia}/${mes}/${anio}`;
}

/** "1234.50" -> "1.234,50 €" */
export function importe(texto) {
    const [entero, decimales = ''] = String(texto).split('.');
    const conMiles = entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${conMiles},${decimales.padEnd(2, '0')} €`;
}

const RELOJ_MADRID = new Intl.DateTimeFormat('es-ES', {
    timeZone: 'Europe/Madrid',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

/** "2026-10-01 08:15:00" (UTC, como lo guarda la BD) -> "01/10/2026 10:15" (hora de Madrid) */
export function fechaHora(utc) {
    const instante = new Date(`${utc.replace(' ', 'T')}Z`);
    const partes = Object.fromEntries(RELOJ_MADRID.formatToParts(instante).map((p) => [p.type, p.value]));

    return `${partes.day}/${partes.month}/${partes.year} ${partes.hour}:${partes.minute}`;
}

/** "pendiente" -> "Pendiente" */
export function capitalizar(texto) {
    return texto.charAt(0).toUpperCase() + texto.slice(1);
}
