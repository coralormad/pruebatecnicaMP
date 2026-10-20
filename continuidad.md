# Continuidad — Prueba técnica PHP Junior · MisterPlan

> **Última actualización:** 21/10/2026 · **Fase actual:** Fase 4 cerrada. **Siguiente: Fase 5 (PHPUnit y revisión de seguridad).**
> Este documento sirve para retomar el proyecto en otra sesión sin repetir la investigación. Si algo de aquí contradice al código, **manda el código**.

---

## 1. Contexto y objetivo

- **Empresa:** MisterPlan, empresa española de software turístico. **Puesto:** Desarrollador/a Junior PHP (presencial en Cáceres los primeros meses).
- **Objetivo:** aplicación de **gestión de reservas de alojamiento** con:
  1. API JSON en PHP nativo;
  2. vistas renderizadas en servidor con Twig;
  3. interfaz HTML + CSS + JavaScript que use la API sin recargar.
- **Prioridad de evaluación:** backend y seguridad. «Si no llegas, prioriza backend».
- **Objetivo personal:** entender y poder **defender** cada decisión en la entrevista.

### Fuentes

| Fuente | Disponible | Nota |
|---|---|---|
| **Enunciado original de MisterPlan** | ✅ | Está en las instrucciones del Project. Contrastado el 17/10: no cambia la arquitectura. |
| Descripción del Project | ✅ | Requisitos ampliados y forma de trabajo. |
| Repositorio `coralormad/pruebatecnicaMP` | ✅ | Rama `main`, sincronizado con el Project. |
| Carpeta local `C:\dev\pruebatecnicaMP` | ✅ **Conectada a Claude** desde el 19/10 | Claude puede leer y escribir archivos. **No** tiene terminal en el equipo: Coral ejecuta los comandos y pega la salida. |

### Lo que el enunciado original aclara

- Plazo: **sin límite**. Tests: **bonus** (se hacen igualmente).
- Solo **cancelar** es obligatorio en la interfaz; confirmar desde la UI es opcional.
- **No** se piden paginación ni control de solapes entre reservas → fuera de alcance, se mencionan en el README.
- `|raw` está permitido si se justifica en el README (decisión: no usarlo).
- Formato de fechas e importes dentro de las plantillas Twig.
- El listado y el detalle son de **reservas**, no de alojamientos (el alojamiento es solo un campo de texto).

---

## 2. Requisitos (resumen)

- PHP nativo **sin frameworks**; MySQL/MariaDB con PDO y consultas preparadas; **Twig obligatorio**; JS vanilla; Composer; PHPUnit opcional.
- Git con **commits pequeños y reales**; `.sql` importable (DDL + datos); **despliegue con URL verificada**; README con setup, decisiones, mejoras y análisis de **1.000 reservas/hora**.
- Modelo: `reserva` y `reserva_evento` (historial). Evento al crear y en cada cambio de estado, **en la misma transacción**.
- API: `GET /api/reservas` (filtros combinables), `GET /api/reservas/{id}`, `POST /api/reservas` (201), `PATCH /api/reservas/{id}/estado`.
- Vistas Twig: listado y detalle con timeline; plantilla base con `extends`/`block`; autoescape.
- JS: filtrar, crear (validación en cliente + errores del servidor), cancelar con confirmación, estados de carga, errores de red/HTTP/JSON; sin JS inline.

---

## 3. Estado real del proyecto (21/10/2026)

### Historial de Git (todo subido a `origin/main`)

```
ea79f0c feat: implement reservation API
a68942d docs: update continuity notes after phases 2-3
b040b7a feat: add reservation data access and business logic
cb87266 feat: add reservation database schema and seed data
724c0db chore: enforce LF line endings
74532ce chore: initialize project structure
7472401 Initial commit   (README.md creado desde GitHub)
```

### Archivos existentes

```
.env               (local, NO versionado; DB_PASS aleatoria generada con openssl)
.env.example       .gitattributes   .gitignore   composer.json   composer.lock
continuidad.md     README.md (solo título)   composer.phar (ignorado)
database/reservas.sql
src/Database.php
src/Exception/{ValidationException,NotFoundException,ConflictException}.php
src/Repository/ReservaRepository.php
src/Service/ReservaService.php
src/Http/{HttpException,Request,JsonResponse,Router}.php
src/Validation/ReservaValidator.php
src/Controller/ReservaApiController.php
public/index.php
tests/api-smoke.sh
public/assets/{css,js}/  templates/reservas/  tests/{Unit,Integration}/   (vacías)
```

### Base de datos

| Elemento | Estado |
|---|---|
| `misterplan` | ✅ Seed verificado el 19/10 (12 reservas, 21 eventos). Las pruebas de la Fase 4 han creado reservas extra (ids 13–15). **Reimportar** `reservas.sql` antes de las Fases 6–7 para trabajar con los datos de ejemplo limpios. |
| `misterplan_test` | Creada **vacía**. Se importará el esquema en la Fase 5. |
| Usuario `misterplan_app@localhost` | `SELECT, INSERT, UPDATE` en `misterplan`; `ALL` en `misterplan_test`. ✅ Verificado: lee y **no** puede hacer `DROP` (ERROR 1142). |

---

## 4. Entorno local verificado

| Herramienta | Versión / ubicación | Estado |
|---|---|---|
| PHP CLI | 8.3.6 de WAMP | ✅ `pdo_mysql`, `intl`, `mbstring`… |
| Composer | 2.10.3 (global) | ✅ |
| Base de datos | **MySQL 8.3.0 de WAMP**, servicio `wampmysqld64`, **puerto 3306** | ✅ `root` sin contraseña (solo local) |
| Dependencias | `twig/twig 3.30.0`, `phpunit/phpunit 11.5.57` (dev) | ✅ |
| Git | 2.55.0, autor `coralormad <coraldpjob@gmail.com>` | ✅ |
| Terminal | Git Bash | — |

Otros servidores en el equipo (**no usar**): MySQL82 (detenido, contraseña desconocida) y MariaDB 10.4 de XAMPP en el **3308** (proyectos antiguos).

### ⚠️ Trampas ya encontradas (evitarlas)

1. **WAMP debe estar arrancado** (icono «W» en verde). Si no: `ERROR 2003 … (10061)`. El de XAMPP en el 3308 no sirve.
2. Los bloques `cat > archivo <<'EOF' … EOF` se pegan en **Git Bash**, nunca en el editor (si no, las líneas `cat`/`EOF` acaban dentro del archivo). Ahora Claude escribe los archivos directamente, así que esto ya no debería pasar.
3. `core.autocrlf=true` en este equipo. Resuelto con `.gitattributes` (`* text=auto eol=lf`).
4. Comandos SQL (`ALTER`, `EXIT`…) solo dentro de la consola `mysql>`. En Git Bash es mejor usar `mysql -e "…"`.
5. Contraseñas tecleadas a ciegas → errores 1045. La de `misterplan_app` se generó con `openssl rand -hex 16` y solo está en `.env`. Para usarla en comandos: `P=$(grep '^DB_PASS=' .env | cut -d= -f2- | tr -d '\r'); …; unset P`.
6. El remoto tenía un commit inicial (`README.md`): se integró con `git rebase origin/main`. **Nunca** `push --force`.
7. **`php.exe` es nativo de Windows y no entiende rutas de Git Bash como `/tmp/...`.** Para pasarle un archivo: ruta relativa dentro del proyecto o redirección `php -r '…' < archivo`.
8. Pegar muchos comandos seguidos en Git Bash puede perder los saltos de línea → `syntax error`. Para baterías largas: script en el repo (`bash tests/api-smoke.sh`).
9. Es un proyecto PHP: **no hay `npm`**. El servidor es siempre `php -S localhost:8000 -t public` (en una terminal aparte, que se deja abierta).

---

## 5. Decisiones técnicas

| ID | Decisión | Motivo |
|---|---|---|
| E1 | WAMP: PHP 8.3 + MySQL 8.3 | Parecido a un hosting típico. |
| E2 | Desarrollo con `php -S localhost:8000 -t public` | Sin configurar Apache. |
| E3 | Proyecto fuera de OneDrive | OneDrive bloquea `vendor/` y `.git/`. |
| D1 | Transiciones: `pendiente→confirmada`, `pendiente→cancelada`, `confirmada→cancelada`. `cancelada` es final. Mismo estado o transición inválida → **409** | Evita estados incoherentes. |
| D2 | **400** = petición no interpretable (JSON mal formado, cuerpo vacío o no objeto, id no válido, filtro mal formado o desconocido). **404** recurso o ruta. **405** + `Allow`. **409** transición. **413** cuerpo > 16 KB. **415** escritura sin `application/json`. **422** datos bien formados que incumplen reglas o tipos, con `fields`. **500** genérico | Distingue formato de validación. Ampliada en la Fase 4 (413, 415). |
| D3 | `fecha_entrada` = igualdad exacta. `desde`/`hasta` = solape: `fecha_entrada <= hasta AND fecha_salida > desde` | El día de salida no es noche ocupada. |
| D4 | `.env` leído con `parse_ini_file(…, INI_SCANNER_RAW)` | Sin librerías; RAW evita que `true`/`no`/`null` se conviertan. |
| D5 | `DECIMAL(10,2)`, `ENUM`, `CHECK`, `utf8mb4_unicode_ci`, índices | Integridad en la BD; compatible con MariaDB. |
| D6 | Usuario `misterplan_app` con mínimo privilegio | Limita el daño de una inyección. |
| D7 | PHPUnit 11: unitarios + integración contra `misterplan_test` | Pruebas sin tocar datos de desarrollo. |
| D8 | `reserva_evento.tipo ENUM('creacion','confirmacion','cancelacion')` (opción A) | Tipado, filtrable, icono por tipo en la timeline. |
| D9 | Índice compuesto `(estado, fecha_entrada)` + `(fecha_entrada, fecha_salida)` + `nombre_huesped` | Regla del prefijo izquierdo. ⚠️ `idx_reserva_nombre` no se usa con `LIKE '%x%'` → documentar en el README (FULLTEXT a gran escala). |
| D10 | `ON DELETE RESTRICT` en la FK | Las reservas se cancelan, no se borran; el historial es auditoría. |
| D11 | `Database` sin estado (fábrica estática), no singleton. `EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC` | Testeable; preparadas nativas; enteros como `int`. |
| D12 | `LIKE … ESCAPE '!'` con escape de `!`, `%` y `_` en el repositorio | Las preparadas evitan inyección, pero no los comodines. |
| D13 | Transacciones en el **servicio** (`enTransaccion()`), no en el repositorio | La unidad de trabajo «reserva + evento» es regla de negocio. |
| D14 | `SELECT … FOR UPDATE` al cambiar de estado | Evita condiciones de carrera entre peticiones simultáneas. |
| D15 | Máquina de estados como constante `TRANSICIONES` | Legible y ampliable. |
| D16 | Excepciones de dominio sin conocimiento de HTTP; el manejador central las traducirá (422/404/409) | Servicio reutilizable fuera de HTTP. |
| D17 | Sin `config/`, `logs/`, `migrations/`, `docs/`, `img/`, `partials/` ni caché de Twig | No justificados por el alcance. |
| D18 | `importe` se maneja como `string` en PHP | PDO devuelve `DECIMAL` como cadena para no perder precisión. |
| D19 | Formato JSON: éxito `{"data": …}`; error `{"error": {"message", "fields"?}}` | El JS siempre sabe dónde mirar. Aprobado por la revisión externa. |
| D20 | Lista cerrada de campos: un campo desconocido en POST/PATCH → **422** «Campo no permitido» (no se ignora) | Bloquea la asignación masiva de forma visible. |
| D21 | Tipos JSON estrictos: textos deben ser `string`; `notas` `string`/`null`; `importe` `string` o número validado como texto con `^\d{1,8}(\.\d{1,2})?$` y `> 0`; arrays/booleanos → 422 | PHP no convierte tipos a escondidas; importe exacto como `DECIMAL(10,2)`. |
| D22 | `fecha_entrada` en el pasado **permitida** | Herramienta interna: reservas tardías legítimas. Documentar en el README. |
| D23 | Cuerpo máximo 16 KB leyendo `php://input` con `MAX + 1` bytes; `json_decode` con profundidad 32 y objetos (`stdClass`) | No depende de `Content-Length`; distingue `{}` de `[]`. |
| D24 | Router: `{id}` acepta cualquier segmento y lo valida el controlador (`^[1-9]\d{0,17}$`) → 400; 405 frente a 404 decidido al final del bucle | `/api/reservas/abc` es 400 (D2), no 404; el orden de rutas no altera 405. |
| D25 | Manejo de errores centralizado en `public/index.php`: `HttpException` → su código; Validation 422; NotFound 404; Conflict 409; `Throwable` → 500 genérico + `error_log`. `display_errors=0`, `set_error_handler` (warnings → excepción), `header_remove('X-Powered-By')`. Detalle solo si `APP_DEBUG=true` | Ningún endpoint trata errores por su cuenta; nunca se filtra información interna. |
| D26 | Cabeceras: `Content-Type: application/json; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`. Sin CORS (mismo origen) | Datos personales no cacheados; el navegador no reinterpreta el JSON. |
| D27 | Sin token CSRF: no hay autenticación ni cookies; las escrituras exigen JSON (415). README: uso interno, no listo para exposición pública sin autenticación | Proporcional al alcance; la revisión externa pidió documentar este límite. |

---

## 6. Arquitectura

```
public/        index.php (front controller) ✅, .htaccess, assets/{css,js}    ← Fase 6/7
src/           Database.php ✅
               Exception/{Validation,NotFound,Conflict}Exception ✅
               Repository/ReservaRepository ✅
               Service/ReservaService ✅
               Http/{HttpException,Router,Request,JsonResponse} ✅
               Validation/ReservaValidator ✅
               Controller/ReservaApiController ✅ · ReservaViewController     ← Fase 6
templates/     base.html.twig · reservas/{listado,detalle}.html.twig          ← Fase 6
database/      reservas.sql ✅
tests/         api-smoke.sh ✅ · Unit/ · Integration/                        ← Fase 5
```

### API pública de las clases ya hechas

- `Database::fromEnv(string $envFile): PDO` · `loadConfig()` · `connect(array $config): PDO`
- `ReservaRepository(PDO)`: `findAll(array $filtros)` (claves: `estado`, `nombre`, `fecha_entrada`, `desde`, `hasta`; **espera filtros ya validados**), `findById(int)`, `findByIdForUpdate(int)`, `findEventos(int)`, `insert(array): int`, `updateEstado(int, string)`, `insertEvento(int, string, string)`
- `ReservaService(PDO, ReservaRepository)`: `listar(array)`, `obtener(int)` (incluye `eventos`; lanza `NotFoundException`), `crear(array)` (datos ya validados; devuelve la reserva con eventos), `cambiarEstado(int, string)` (lanza `ValidationException`/`NotFoundException`/`ConflictException`)
- `ValidationException(array $errors, string $message)` → `getErrors()`
- `HttpException(int $status, string $message, array $fields = [], array $headers = [])` → `getStatus()`, `getFields()`, `getHeaders()`
- `Request::fromGlobals()` → `getMethod()`, `getPath()`, `getQuery()`, `getJsonBody(): array` (415/413/400). Constructor privado: para tests, ver Fase 5.
- `JsonResponse::data($data, int $status = 200)` · `JsonResponse::error(int, string, array $fields = [], array $headers = [])` → `getStatus()`, `getPayload()`, `send()`
- `Router`: `get/post/patch(string $pattern, Closure)`, `dispatch(Request): mixed` (closure recibe `Request` y `array $params`)
- `ReservaValidator`: `validarCreacion(array): array` (422), `validarCambioEstado(array): string` (422), `validarFiltros(array): array` (400), `validarId(string): int` (400)
- `ReservaApiController(ReservaService, ReservaValidator)`: `listar`, `detalle`, `crear` (201), `cambiarEstado`

---

## 7. Plan y estado de las fases

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Auditoría y plan | ✅ 17/10 |
| 2 | Estructura, Composer, `.gitignore`, `.env`, `.gitattributes`, SQL, BD y usuario | ✅ 19/10 |
| 3 | PDO, repositorio, servicio y transacciones | ✅ 19/10 (rollback forzado verificado) |
| 4 | API, validación y errores | ✅ 21/10 (21/21 en `tests/api-smoke.sh`) |
| 5 | PHPUnit y revisión de seguridad | ⏭ **Siguiente** |
| 6 | Twig: listado y detalle | Pendiente |
| 7 | JavaScript: filtros, creación y cancelación | Pendiente |
| 8 | Revisión completa y README | Pendiente |
| 9 | Despliegue verificado, escalabilidad y preparación de la entrevista | Pendiente |

Plan temporal de referencia: `Plan_ejecucion_MisterPlan.docx` (≈53 h; hito «backend entregable» al cerrar la Fase 5).

### Pruebas ya ejecutadas y verificadas

- Fase 2: `composer validate`/`install`; importación; recuentos (5 confirmadas, 4 pendientes, 3 canceladas; 12/6/3 eventos); búsqueda sin tildes; `CHECK` de fechas (ERROR 3819); FK (ERROR 1452); mínimo privilegio (ERROR 1142).
- Fase 3: conexión desde `.env`; 10 casos del repositorio (filtros combinados, solape → solo la 11, `%` → 0 resultados, `O'Connor`, id 999 → NULL); 8 casos del servicio (transiciones válidas, 409, 404, 422, historial); **rollback** forzado renombrando `reserva_evento` (antes 13 / después 13).
- Fase 4: propuesta revisada por una IA externa (APROBADO CON CAMBIOS; los 5 cambios obligatorios aplicados). Validador ejecutado contra 20 casos límite. `bash tests/api-smoke.sh` en el equipo de Coral: **21 OK, 0 fallos** (200, 201, 400 ×4, 404 ×3, 405, 409 ×2, 415, 422 ×2 e historial `creacion,confirmacion,cancelacion`). Verificado además en un entorno de pruebas aparte: 413 con 17 KB, cabeceras de seguridad, sin `X-Powered-By`, 500 genérico con la BD caída (detalle solo en `error_log`) y detalle visible solo con `APP_DEBUG=true`.

---

## 8. Próximos pasos (en orden)

1. **Fase 5 — PHPUnit y revisión de seguridad** (propuesta pendiente de aprobación):
   - Importar el esquema en `misterplan_test` y crear `phpunit.xml` (suites `Unit` e `Integration`) y un `.env.testing` fuera de Git.
   - Tests unitarios de `ReservaValidator` (tipos, fechas, importe, filtros, id) y de `Router` (404/405).
   - Tests de integración de `ReservaService` contra `misterplan_test`: crear + evento, transiciones válidas e inválidas (409), 404, rollback.
   - Checklist de seguridad documentada (para el README).
   - **Hecho cuando:** `vendor/bin/phpunit` en verde, visto en el equipo de Coral.
   - Commit previsto: `test: cover validation, transitions and API errors`.
2. Elegir hosting antes de la Fase 6 (riesgos: Composer/SSH, raíz en `public/`, versión de MariaDB y soporte de `CHECK`).

### Pendientes anotados para el README

- **Limitación:** la conexión PDO se abre antes de enrutar; con la BD caída, una ruta inexistente da 500 en vez de 404 (mejora: conexión perezosa).
- `fecha_entrada` en el pasado permitida (D22). Sin autenticación ni CSRF: alcance interno (D27).
- `idx_reserva_nombre` no se usa con `LIKE '%x%'` (D9). Sin paginación ni control de solapes.
- `APP_DEBUG=false` obligatorio en el despliegue (checklist de la Fase 9).

---

## 9. Instrucciones para retomar en otra sesión

- Leer este archivo y las instrucciones del Project (enunciado original) antes de proponer cambios.
- Trabajar **paso a paso** y esperar la aprobación de Coral antes de cambiar de fase.
- **Explicar cada archivo y cada decisión** para que Coral pueda defenderlo en la entrevista.
- Prefijos: 💻 TERMINAL, 📄 ARCHIVO NUEVO, ✏️ ARCHIVO EXISTENTE; rutas exactas siempre.
- Con la carpeta conectada, Claude escribe los archivos directamente (`C:\dev\pruebatecnicaMP`). Coral ejecuta los comandos de comprobación y pega la salida. Si la carpeta no aparece conectada, pedir acceso de nuevo.
- Respuestas concisas y económicas en tokens.
- No hacer commits, pushes ni operaciones destructivas sin aprobación. Commits con `git add` de archivos concretos y mensajes Conventional Commits en inglés.
- No afirmar que algo funciona sin haber visto la salida real. Si Coral pega el comando sin la salida, pedirla.

---

## 10. Registro de cambios

- **14–15/10/2026:** repositorio creado y clonado; auditoría del entorno; `PATH` reparado; Composer instalado; diagnóstico de MySQL82.
- **17/10/2026:** cambio a MySQL 8.3 de WAMP; Fase 1 aprobada (D1–D7); proyecto movido a `C:\dev\pruebatecnicaMP`; enunciado original contrastado; plan con estimación de tiempos (`Plan_ejecucion_MisterPlan.docx`).
- **18/10/2026:** remoto `origin` reconfigurado; BD `misterplan` y `misterplan_test` creadas; usuario `misterplan_app`; estructura de carpetas creada.
- **19/10/2026:** archivos base (Composer, `.gitignore`, `.env.example`, `.gitattributes`); `reservas.sql` con 12 reservas y 21 eventos; contraseña de la app regenerada; carpeta conectada a Claude; Fase 3 completa (Database, excepciones, repositorio, servicio) con rollback verificado. Commits `74532ce` → `b040b7a`.
- **19–21/10/2026:** Fase 4 completa. Propuesta revisada por una IA externa (cambios obligatorios aplicados: tipos estrictos, 413/415, límite de cuerpo, 404/405, filtros). Archivos `Http/*`, `ReservaValidator`, `ReservaApiController`, `public/index.php` y `tests/api-smoke.sh`. Smoke test 21/21 en el equipo de Coral. Commit `ea79f0c`.
