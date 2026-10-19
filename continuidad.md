# Continuidad — Prueba técnica PHP Junior · MisterPlan

> **Última actualización:** 19/10/2026 · **Fase actual:** Fases 2 y 3 cerradas. **Siguiente: Fase 4 (API, validación y errores).**
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

## 3. Estado real del proyecto (19/10/2026)

### Historial de Git (todo subido a `origin/main`)

```
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
public/assets/{css,js}/  src/{Controller,Http,Validation}/  templates/reservas/  tests/{Unit,Integration}/   (vacías)
```

### Base de datos

| Elemento | Estado |
|---|---|
| `misterplan` | ✅ Esquema importado con 12 reservas y 21 eventos. **Comprobar** que tiene 12 reservas: tras la prueba de rollback se ejecutó la reimportación, pero su salida no se llegó a verificar. |
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

---

## 5. Decisiones técnicas

| ID | Decisión | Motivo |
|---|---|---|
| E1 | WAMP: PHP 8.3 + MySQL 8.3 | Parecido a un hosting típico. |
| E2 | Desarrollo con `php -S localhost:8000 -t public` | Sin configurar Apache. |
| E3 | Proyecto fuera de OneDrive | OneDrive bloquea `vendor/` y `.git/`. |
| D1 | Transiciones: `pendiente→confirmada`, `pendiente→cancelada`, `confirmada→cancelada`. `cancelada` es final. Mismo estado o transición inválida → **409** | Evita estados incoherentes. |
| D2 | **400** = petición no interpretable (JSON mal formado, id no numérico, filtro mal formado). **422** = datos bien formados que incumplen reglas. 404, 405, 500 sin detalles internos | Distingue formato de validación. |
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

---

## 6. Arquitectura

```
public/        index.php (front controller), .htaccess, assets/{css,js}      ← Fase 4/6/7
src/           Database.php ✅
               Exception/{Validation,NotFound,Conflict}Exception ✅
               Repository/ReservaRepository ✅
               Service/ReservaService ✅
               Http/{Router,Request,JsonResponse}                             ← Fase 4
               Validation/ReservaValidator                                     ← Fase 4
               Controller/{ReservaApiController,ReservaViewController}        ← Fase 4/6
templates/     base.html.twig · reservas/{listado,detalle}.html.twig          ← Fase 6
database/      reservas.sql ✅
tests/         Unit/ · Integration/                                           ← Fase 5
```

### API pública de las clases ya hechas

- `Database::fromEnv(string $envFile): PDO` · `loadConfig()` · `connect(array $config): PDO`
- `ReservaRepository(PDO)`: `findAll(array $filtros)` (claves: `estado`, `nombre`, `fecha_entrada`, `desde`, `hasta`; **espera filtros ya validados**), `findById(int)`, `findByIdForUpdate(int)`, `findEventos(int)`, `insert(array): int`, `updateEstado(int, string)`, `insertEvento(int, string, string)`
- `ReservaService(PDO, ReservaRepository)`: `listar(array)`, `obtener(int)` (incluye `eventos`; lanza `NotFoundException`), `crear(array)` (datos ya validados; devuelve la reserva con eventos), `cambiarEstado(int, string)` (lanza `ValidationException`/`NotFoundException`/`ConflictException`)
- `ValidationException(array $errors, string $message)` → `getErrors()`

---

## 7. Plan y estado de las fases

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Auditoría y plan | ✅ 17/10 |
| 2 | Estructura, Composer, `.gitignore`, `.env`, `.gitattributes`, SQL, BD y usuario | ✅ 19/10 |
| 3 | PDO, repositorio, servicio y transacciones | ✅ 19/10 (rollback forzado verificado) |
| 4 | API, validación y errores | ⏭ **Siguiente** |
| 5 | PHPUnit y revisión de seguridad | Pendiente |
| 6 | Twig: listado y detalle | Pendiente |
| 7 | JavaScript: filtros, creación y cancelación | Pendiente |
| 8 | Revisión completa y README | Pendiente |
| 9 | Despliegue verificado, escalabilidad y preparación de la entrevista | Pendiente |

Plan temporal de referencia: `Plan_ejecucion_MisterPlan.docx` (≈53 h; hito «backend entregable» al cerrar la Fase 5).

### Pruebas ya ejecutadas y verificadas

- Fase 2: `composer validate`/`install`; importación; recuentos (5 confirmadas, 4 pendientes, 3 canceladas; 12/6/3 eventos); búsqueda sin tildes; `CHECK` de fechas (ERROR 3819); FK (ERROR 1452); mínimo privilegio (ERROR 1142).
- Fase 3: conexión desde `.env`; 10 casos del repositorio (filtros combinados, solape → solo la 11, `%` → 0 resultados, `O'Connor`, id 999 → NULL); 8 casos del servicio (transiciones válidas, 409, 404, 422, historial); **rollback** forzado renombrando `reserva_evento` (antes 13 / después 13).

---

## 8. Próximos pasos (en orden)

1. **Comprobar los datos de ejemplo** (con WAMP arrancado):
   ```bash
   mysql -u root -h 127.0.0.1 -P 3306 misterplan -e "SELECT COUNT(*) reservas FROM reserva;"
   ```
   Si no da 12: `mysql -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 misterplan < database/reservas.sql`
2. **Fase 4 — API** (propuesta pendiente de aprobación):
   - `src/Http/Request.php`: método, ruta, query y cuerpo JSON (JSON mal formado → 400).
   - `src/Http/JsonResponse.php`: JSON homogéneo, código HTTP y cabeceras de seguridad.
   - `src/Http/Router.php`: método + ruta → controlador; 404 ruta desconocida, 405 método no permitido.
   - `src/Validation/ReservaValidator.php`: datos de creación y filtros; lista cerrada de campos; longitudes como en el SQL (nombre 120, email 254, alojamiento 150, notas 1000); importe > 0 con máximo 2 decimales.
   - `src/Controller/ReservaApiController.php`: los 4 endpoints.
   - `public/index.php`: front controller + manejador de errores centralizado (sin trazas si `APP_DEBUG=false`).
   - Exigir `Content-Type: application/json` en escrituras (alternativa a CSRF, justificar en el README).
   - **Hecho cuando:** los 4 endpoints responden con los códigos de D2, probado con `curl`.
   - Commit previsto: `feat: implement reservation API`.
3. Fase 5: importar el esquema en `misterplan_test`, `phpunit.xml` y convertir las pruebas manuales de las Fases 3–4 en tests.
4. Elegir hosting antes de la Fase 6 (riesgos: Composer/SSH, raíz en `public/`, versión de MariaDB y soporte de `CHECK`).

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
