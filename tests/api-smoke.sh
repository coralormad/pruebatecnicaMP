#!/usr/bin/env bash
# Prueba manual de humo de la API: comprueba el código HTTP de cada caso del contrato D2.
# Uso (con el servidor arrancado: php -S localhost:8000 -t public):
#   bash tests/api-smoke.sh [URL_BASE]
# Crea UNA reserva de prueba en la BD de desarrollo y la confirma y cancela.

set -u
B="${1:-http://localhost:8000}"
J='Content-Type: application/json'
TMP="$(dirname "$0")/.smoke-response.json"   # dentro del proyecto: lo ven bash, curl y php.exe en Windows
ok=0; fallos=0

trap 'rm -f "$TMP"' EXIT

# t <código esperado> <descripción> <argumentos de curl...>
t() {
    local esperado="$1" nombre="$2"; shift 2
    local codigo
    codigo=$(curl -s -o "$TMP" -w '%{http_code}' "$@")
    if [ "$codigo" = "$esperado" ]; then
        ok=$((ok + 1)); printf '  OK    %-24s %s\n' "$nombre" "$codigo"
    else
        fallos=$((fallos + 1)); printf '  FALLO %-24s %s (esperado %s)  %s\n' "$nombre" "$codigo" "$esperado" "$(head -c 120 "$TMP")"
    fi
}

echo "Lectura"
t 200 "listar"              "$B/api/reservas"
t 200 "filtros combinados"  "$B/api/reservas?estado=pendiente&nombre=a&desde=2026-01-01&hasta=2026-12-31"
t 400 "filtro inválido"     "$B/api/reservas?estado=x&foo=1"
t 200 "detalle 1"           "$B/api/reservas/1"
t 404 "detalle 999"         "$B/api/reservas/999"
t 400 "id abc"              "$B/api/reservas/abc"
t 404 "ruta inexistente"    "$B/api/nada"
t 405 "método DELETE"       -X DELETE "$B/api/reservas/1"

echo "Creación"
t 415 "sin Content-Type"    -X POST "$B/api/reservas" -d '{}'
t 400 "JSON roto"           -X POST -H "$J" "$B/api/reservas" -d '{mal'
t 400 "JSON no objeto"      -X POST -H "$J" "$B/api/reservas" -d '[]'
t 422 "datos inválidos"     -X POST -H "$J" "$B/api/reservas" -d '{"nombre_huesped":"Ana","estado":"confirmada","importe":true}'
t 201 "crear"               -X POST -H "$J" "$B/api/reservas" -d '{"nombre_huesped":"Prueba Smoke","email_huesped":"smoke@example.com","nombre_alojamiento":"Casa Test","fecha_entrada":"2026-12-01","fecha_salida":"2026-12-04","importe":"350.00"}'

# php lee el JSON por stdin (lo redirige bash), así no depende de rutas Windows/Unix.
ID=$(php -r '$d = json_decode(stream_get_contents(STDIN), true); echo (int) ($d["data"]["id"] ?? 0);' < "$TMP" 2>/dev/null)
echo "Cambios de estado (reserva $ID)"
t 200 "confirmar"           -X PATCH -H "$J" "$B/api/reservas/$ID/estado" -d '{"estado":"confirmada"}'
t 409 "confirmar otra vez"  -X PATCH -H "$J" "$B/api/reservas/$ID/estado" -d '{"estado":"confirmada"}'
t 422 "estado no válido"    -X PATCH -H "$J" "$B/api/reservas/$ID/estado" -d '{"estado":"pendiente"}'
t 200 "cancelar"            -X PATCH -H "$J" "$B/api/reservas/$ID/estado" -d '{"estado":"cancelada"}'
t 409 "reabrir cancelada"   -X PATCH -H "$J" "$B/api/reservas/$ID/estado" -d '{"estado":"confirmada"}'
t 404 "PATCH reserva 999"   -X PATCH -H "$J" "$B/api/reservas/999/estado" -d '{"estado":"cancelada"}'

echo "Historial"
t 200 "detalle creada"      "$B/api/reservas/$ID"
TIPOS=$(php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_column($d["data"]["eventos"] ?? [], "tipo"));' < "$TMP" 2>/dev/null)
if [ "$TIPOS" = "creacion,confirmacion,cancelacion" ]; then
    ok=$((ok + 1)); echo "  OK    eventos                  $TIPOS"
else
    fallos=$((fallos + 1)); echo "  FALLO eventos                  '$TIPOS' (esperado creacion,confirmacion,cancelacion)"
fi

echo
echo "Resultado: $ok OK, $fallos fallos"
[ "$fallos" -eq 0 ]
