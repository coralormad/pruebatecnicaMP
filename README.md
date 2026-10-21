# pruebatecnicaMP — Reservas de alojamiento

Prueba técnica para el puesto de Desarrollador/a Junior PHP en MisterPlan: API JSON en PHP nativo (sin framework), vistas Twig e interfaz en JavaScript vanilla.

> **Estado:** en desarrollo. El backend (API, validación, errores) y sus tests están terminados. Faltan las vistas Twig, el JavaScript, la documentación completa de instalación y el despliegue.

## Pruebas

```bash
vendor/bin/phpunit                       # todo (69 tests)
vendor/bin/phpunit --testsuite Unit      # sin base de datos
bash tests/api-smoke.sh                  # contrato HTTP con curl (servidor arrancado)
```

- **Unit:** `ReservaValidator` (tipos, fechas, importe, asignación masiva, filtros, id) y `Router` (404, 405 + `Allow`).
- **Integration:** `ReservaService` + `ReservaRepository` contra la BD `misterplan_test` (configurada en `.env.testing`, fuera de Git): creación con su evento, transiciones de estado, rollback, filtros y concurrencia. Si falta `.env.testing`, estos tests se marcan como omitidos. Por seguridad se niegan a ejecutarse si `DB_NAME` no termina en `_test`, porque vacían las tablas.

## Seguridad

La API recibe datos de fuera, así que no se fía de nada que llegue en la petición. Cada medida indica dónde está y cómo se ha comprobado.

| # | Riesgo | Medida | Dónde | Comprobación |
|---|---|---|---|---|
| 1 | Inyección SQL | Todas las consultas son preparadas; ningún valor del usuario se concatena en el SQL. Preparadas nativas (`ATTR_EMULATE_PREPARES = false`) | `src/Repository/ReservaRepository.php`, `src/Database.php` | Revisión de código; test de id `1 OR 1=1` → 400; tests de filtros contra MySQL |
| 2 | Comodines en la búsqueda | `%` y `_` del usuario se escapan (`LIKE … ESCAPE '!'`) | `ReservaRepository::escaparLike()` | Test: buscar `%` devuelve solo la reserva que lo contiene |
| 3 | Asignación masiva | Lista cerrada de campos: un campo no permitido (`estado`, `id`…) → 422. El `INSERT` solo usa 7 columnas fijas | `ReservaValidator::CAMPOS_CREACION`, `ReservaRepository::insert()` | Tests «intento de fijar el estado / el id» |
| 4 | Datos inválidos | Validación en servidor con tipos JSON estrictos, longitudes iguales a las del SQL, fechas reales `AAAA-MM-DD`, salida > entrada, email, importe validado como texto (nunca `float`) | `src/Validation/ReservaValidator.php` | 45 tests unitarios |
| 5 | Estados incoherentes | Máquina de estados: `cancelada` es final; transición no permitida → 409 sin modificar nada | `ReservaService::TRANSICIONES` | Tests de transiciones válidas e inválidas |
| 6 | Condiciones de carrera | `SELECT … FOR UPDATE` dentro de la transacción del cambio de estado | `ReservaService::cambiarEstado()` | Test con dos procesos: no se puede confirmar una reserva que otra petición está cancelando (y falla si se quita el `FOR UPDATE`) |
| 7 | Datos a medias | Reserva + evento de historial en la misma transacción; rollback ante cualquier error | `ReservaService::enTransaccion()` | Test que fuerza el fallo del segundo `INSERT` |
| 8 | Fuga de información en errores | Manejo de errores centralizado: 500 con mensaje genérico; detalle solo en el log. `display_errors = 0`, warnings convertidos en excepción, sin cabecera `X-Powered-By`. El detalle solo se muestra con `APP_DEBUG=true` | `public/index.php` | Comprobado con la BD caída |
| 9 | Peticiones abusivas o mal formadas | Cuerpo máximo 16 KB (413), profundidad JSON 32, cuerpo debe ser un objeto JSON (400), `Content-Type: application/json` obligatorio en escrituras (415) | `src/Http/Request.php` | `tests/api-smoke.sh` y pruebas con `curl` |
| 10 | Métodos no previstos | Solo los métodos registrados; el resto → 405 con cabecera `Allow` | `src/Http/Router.php` | Test unitario y smoke test |
| 11 | CSRF | No hay sesiones ni cookies, así que no hay credencial que otra web pueda reutilizar. Además, las escrituras exigen JSON: un formulario de otro sitio no puede enviarlo sin una petición previa `OPTIONS`, que la API rechaza (405, sin cabeceras CORS) | `src/Http/Request.php` | Razonamiento documentado; si se añadiera autenticación con cookies, haría falta un token CSRF |
| 12 | Credenciales | `.env` fuera de `public/` y fuera de Git; `.env.example` sin datos reales. Usuario de BD `misterplan_app` con mínimo privilegio (`SELECT, INSERT, UPDATE`), nunca `root` | `.gitignore`, `.env.example` | `DROP` rechazado (ERROR 1142); historial de Git revisado sin secretos |
| 13 | Integridad en la base de datos | `ENUM`, `CHECK (fecha_salida > fecha_entrada)`, `CHECK (importe > 0)`, `DECIMAL(10,2)`, FK `ON DELETE RESTRICT` | `database/reservas.sql` | `CHECK` (ERROR 3819) y FK (ERROR 1452) comprobados |
| 14 | Caché y tipo de contenido | `Content-Type: application/json; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store` | `src/Http/JsonResponse.php` | Cabeceras comprobadas con `curl -i` |
| 15 | XSS | Los datos se guardan tal cual y se escapan **al mostrarlos**: autoescape de Twig (sin `|raw`) y `textContent` en JavaScript | Vistas y JS (pendientes) | Se comprobará con un nombre `<img src=x onerror=alert(1)>` |
| 16 | Archivos expuestos en el servidor | Solo `public/` es accesible por la web; `APP_DEBUG=false` en producción | Despliegue (pendiente) | Se comprobará que `/.env`, `/vendor/` y `/database/reservas.sql` devuelven 403/404 |

### Límites conocidos

- **Sin autenticación ni límite de peticiones:** es una herramienta interna de demostración con datos ficticios. En producción haría falta autenticación, roles y *rate limiting*.
- **Doble envío:** un doble clic o un reintento de red puede crear dos reservas. La interfaz desactivará el botón mientras la petición está en curso; una solución completa sería una clave de idempotencia.
