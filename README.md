# pruebatecnicaMP — Reservas de alojamiento

Aplicación para gestionar reservas de alojamiento: una **API JSON en PHP nativo** (sin framework), **vistas renderizadas con Twig** y una **interfaz en JavaScript vanilla** que consume la API para filtrar, crear y cancelar reservas sin recargar la página.

Prueba técnica para el puesto de Desarrollador/a Junior PHP en MisterPlan.

**Demo:** _pendiente de publicar_

## Índice

1. [Stack](#stack)
2. [Instalación en local](#instalación-en-local)
3. [Uso](#uso)
4. [API](#api)
5. [Pruebas](#pruebas)
6. [Arquitectura y decisiones](#arquitectura-y-decisiones)
7. [Seguridad](#seguridad)
8. [Limitaciones y qué haría con más tiempo](#limitaciones-y-qué-haría-con-más-tiempo)
9. [Escalabilidad: 1.000 reservas por hora](#escalabilidad-1000-reservas-por-hora)

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3 nativo, PDO |
| Base de datos | MySQL 8 o MariaDB 10.4+ (`utf8mb4`) |
| Vistas | Twig 3 |
| Frontend | HTML semántico, CSS sin librerías, JavaScript vanilla (módulos ES) |
| Dependencias | Composer (Twig; PHPUnit en desarrollo) |
| Tests | PHPUnit 11 |

## Instalación en local

### Requisitos

- PHP **8.3** con las extensiones `pdo_mysql` y `mbstring`.
- **MySQL 8** o **MariaDB 10.4+** (por ejemplo, con WAMP o MAMP).
- [Composer](https://getcomposer.org/).

### 1. Clonar e instalar dependencias

```bash
git clone https://github.com/coralormad/pruebatecnicaMP.git
cd pruebatecnicaMP
composer install
```

### 2. Crear la base de datos y el usuario de la aplicación

Desde la consola de MySQL (o phpMyAdmin) como `root`:

```sql
CREATE DATABASE misterplan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'misterplan_app'@'localhost' IDENTIFIED BY 'una_contraseña_segura';
GRANT SELECT, INSERT, UPDATE ON misterplan.* TO 'misterplan_app'@'localhost';
```

La aplicación no se conecta como `root`: su usuario solo puede leer, insertar y actualizar.

### 3. Importar el esquema y los datos de ejemplo

```bash
mysql -u root misterplan < database/reservas.sql
```

`database/reservas.sql` crea las tablas `reserva` y `reserva_evento` y carga 12 reservas en los tres estados, con su historial. Se puede volver a importar en cualquier momento para dejar los datos como al principio. En phpMyAdmin: seleccionar la base `misterplan` → **Importar** → elegir el archivo.

### 4. Configurar la conexión

```bash
cp .env.example .env
```

Editar `.env` con los datos del paso 2:

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=misterplan
DB_USER=misterplan_app
DB_PASS=una_contraseña_segura
APP_DEBUG=false
```

`.env` está fuera de `public/` y excluido de Git.

### 5. Arrancar

```bash
php -S localhost:8000 -t public
```

Abrir **http://localhost:8000**.

> Con Apache, el `DocumentRoot` debe ser la carpeta `public/`. El archivo `public/.htaccess` envía todas las rutas a `index.php`.

## Uso

| Página | Qué se puede hacer |
|---|---|
| `/reservas` (también `/`) | Ver el listado, filtrar por estado, nombre del huésped y rango de fechas, crear una reserva y cancelar |
| `/reservas/{id}` | Ver todos los datos de la reserva y su historial, y cancelarla |

Sin JavaScript, el listado y los filtros siguen funcionando (formulario GET). Crear y cancelar necesitan JavaScript porque usan la API.

## API

Todas las respuestas son JSON con un formato homogéneo:

- **Éxito:** `{"data": ...}`
- **Error:** `{"error": {"message": "...", "fields": {"campo": "motivo"}}}` (`fields` solo aparece en los errores de validación)

| Método | Ruta | Qué hace | Éxito |
|---|---|---|---|
| `GET` | `/api/reservas` | Lista las reservas, con filtros combinables | 200 |
| `GET` | `/api/reservas/{id}` | Detalle de una reserva con su historial (`eventos`) | 200 |
| `POST` | `/api/reservas` | Crea una reserva (siempre en estado `pendiente`) y su evento | 201 |
| `PATCH` | `/api/reservas/{id}/estado` | Confirma o cancela una reserva | 200 |

### Filtros de `GET /api/reservas`

Todos son opcionales y se pueden combinar. Un parámetro vacío se ignora.

| Parámetro | Ejemplo | Comportamiento |
|---|---|---|
| `estado` | `pendiente` | `pendiente`, `confirmada` o `cancelada` |
| `nombre` | `fernández` | Búsqueda parcial en el nombre del huésped, sin distinguir mayúsculas ni tildes |
| `desde` / `hasta` | `2026-11-08` | Reservas cuya **estancia ocupa alguna noche** del periodo (ver abajo) |
| `fecha_entrada` | `2026-11-06` | Reservas que entran exactamente ese día |

**Cómo funciona el rango.** Una reserva del 6 al 9 de noviembre ocupa las noches del 6, 7 y 8; el día 9 el huésped se va. Por eso:

- `desde=2026-11-08&hasta=2026-11-08` **la incluye** (la noche del 8 está ocupada).
- `desde=2026-11-09&hasta=2026-11-09` **no la incluye** (el 9 sale).

En SQL: `fecha_entrada <= :hasta AND fecha_salida > :desde`.

### Ejemplos

**Listar con filtros**

```bash
curl "http://localhost:8000/api/reservas?estado=pendiente&nombre=fern"
```

```json
{
    "data": [
        {
            "id": 5,
            "nombre_huesped": "Ana Fernández López",
            "email_huesped": "ana.fernandez@example.com",
            "nombre_alojamiento": "Villa Costa de la Luz",
            "fecha_entrada": "2026-12-04",
            "fecha_salida": "2026-12-08",
            "estado": "pendiente",
            "importe": "740.00",
            "notas": null,
            "fecha_creacion": "2026-10-14 16:20:00"
        },
        {
            "id": 8,
            "nombre_huesped": "Pablo Fernández Ruiz",
            "...": "..."
        }
    ]
}
```

**Detalle con historial**

```bash
curl http://localhost:8000/api/reservas/4
```

```json
{
    "data": {
        "id": 4,
        "nombre_huesped": "John O'Connor",
        "email_huesped": "john.oconnor@example.com",
        "nombre_alojamiento": "Hostal Plaza Nueva",
        "fecha_entrada": "2026-11-20",
        "fecha_salida": "2026-11-23",
        "estado": "confirmada",
        "importe": "156.00",
        "notas": "Necesita cuna para bebé",
        "fecha_creacion": "2026-10-05 12:00:00",
        "eventos": [
            { "id": 6, "tipo": "creacion", "descripcion": "Reserva creada en estado pendiente", "fecha_creacion": "2026-10-05 12:00:00" },
            { "id": 7, "tipo": "confirmacion", "descripcion": "Estado cambiado de pendiente a confirmada", "fecha_creacion": "2026-10-05 17:30:00" }
        ]
    }
}
```

**Crear**

```bash
curl -X POST http://localhost:8000/api/reservas \
  -H "Content-Type: application/json" \
  -d '{"nombre_huesped":"Ana Prueba","email_huesped":"ana@example.com","nombre_alojamiento":"Hostal Plaza Nueva","fecha_entrada":"2026-12-10","fecha_salida":"2026-12-12","importe":"150.50","notas":"Llega en tren"}'
```

Responde **201** con la reserva creada (estado `pendiente`) y su evento `creacion`. `notas` es opcional. El `importe` se envía como texto (`"150.50"`) o como entero (`150`); un número con decimales se rechaza para evitar redondeos.

**Error de validación (422)**

```json
{
    "error": {
        "message": "Los datos enviados no son válidos.",
        "fields": {
            "estado": "Campo no permitido.",
            "nombre_alojamiento": "Campo obligatorio.",
            "email_huesped": "El email no tiene un formato válido.",
            "fecha_salida": "Debe ser posterior a la fecha de entrada.",
            "importe": "Debe ser mayor que 0."
        }
    }
}
```

**Cambiar el estado**

```bash
curl -X PATCH http://localhost:8000/api/reservas/2/estado \
  -H "Content-Type: application/json" \
  -d '{"estado":"confirmada"}'
```

Responde **200** con la reserva actualizada y su historial.

| Desde \ Hacia | `confirmada` | `cancelada` |
|---|---|---|
| `pendiente` | ✅ | ✅ |
| `confirmada` | 409 (ya lo está) | ✅ |
| `cancelada` | 409 (estado final) | 409 (ya lo está) |

```json
{"error":{"message":"No se puede pasar una reserva de cancelada a confirmada."}}
```

### Códigos de estado

| Código | Cuándo |
|---|---|
| 200 / 201 | Operación correcta / reserva creada |
| 400 | Petición que no se puede interpretar: JSON mal formado o que no es un objeto, id no válido (`/api/reservas/abc`), filtro desconocido o con formato incorrecto |
| 404 | La reserva o la ruta no existen |
| 405 | Método no permitido en una ruta existente (incluye la cabecera `Allow`) |
| 409 | Cambio de estado no permitido |
| 413 | Cuerpo de más de 16 KB |
| 415 | Escritura sin `Content-Type: application/json` |
| 422 | Datos con formato correcto que no cumplen las reglas (errores en `fields`) |
| 500 | Error inesperado: mensaje genérico, el detalle solo va al log del servidor |

## Pruebas

```bash
vendor/bin/phpunit                         # todos los tests (88)
vendor/bin/phpunit --testsuite Unit        # solo los que no usan base de datos
bash tests/api-smoke.sh                    # contrato HTTP con curl (servidor arrancado)
```

| Suite | Qué cubre |
|---|---|
| **Unit** (70) | Validador (tipos, fechas, importe, asignación masiva, filtros, id), router (404, 405 + `Allow`), manejador de errores (código por excepción, 500 sin detalles) y plantillas Twig (herencia, formato español, zona horaria, **escape de HTML**, sin JavaScript en línea) |
| **Integration** (18) | Servicio y repositorio contra MySQL: creación con su evento, **rollback** si falla el evento, transiciones válidas e inválidas, filtros combinados y bordes del rango, y una **prueba de concurrencia** con dos procesos |

**Tests de integración.** Necesitan una base de datos aparte, porque **vacían las tablas** en cada test:

```bash
mysql -u root -e "CREATE DATABASE misterplan_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON misterplan_test.* TO 'misterplan_app'@'localhost';"
mysql -u root misterplan_test < database/reservas.sql
sed 's/^DB_NAME=.*/DB_NAME=misterplan_test/' .env > .env.testing
```

Sin `.env.testing`, estos tests se marcan como omitidos. Si `DB_NAME` no termina en `_test`, se niegan a ejecutarse.

**Smoke test.** `tests/api-smoke.sh` comprueba 21 casos del contrato HTTP. Crea una reserva de prueba en la base de datos de desarrollo; después se puede volver a importar `database/reservas.sql`.

## Arquitectura y decisiones

```
public/            único directorio accesible por la web
  index.php        front controller: registra las rutas y delega los errores
  .htaccess        en Apache, envía todas las rutas a index.php
  assets/          CSS y JavaScript
src/
  Http/            Request, Router, respuestas JSON y HTML, ManejadorErrores
  Controller/      ReservaApiController (JSON) y ReservaViewController (Twig)
  Validation/      ReservaValidator
  Service/         ReservaService: reglas de negocio y transacciones
  Repository/      ReservaRepository: solo SQL con consultas preparadas
  Exception/       excepciones de dominio (no saben nada de HTTP)
  View/            TwigFactory
  Database.php     conexión PDO a partir de .env
templates/         plantillas Twig (base, listado, detalle, error)
database/          reservas.sql (esquema + datos de ejemplo)
tests/             Unit, Integration y smoke test
```

**Recorrido de una petición:** `index.php` → `Router` → controlador → `ReservaValidator` → `ReservaService` → `ReservaRepository` → base de datos. Cualquier excepción sube hasta `ManejadorErrores`, que la convierte en la respuesta adecuada.

**Por qué esta organización de clases**

- **Cada capa tiene una sola responsabilidad.** El controlador solo traduce HTTP; el validador decide si los datos de entrada son aceptables; el servicio aplica las reglas de negocio (máquina de estados) y abre las transacciones; el repositorio solo contiene SQL. Así se pueden probar por separado y un cambio en una no obliga a tocar las demás.
- **La transacción está en el servicio, no en el repositorio**, porque «guardar la reserva y su evento juntos» es una regla de negocio, no un detalle de acceso a datos.
- **Las excepciones de dominio no conocen HTTP.** El servicio lanza `NotFoundException` o `ConflictException`, y el manejador central decide que son un 404 o un 409. Ningún endpoint gestiona errores por su cuenta.
- **La API y las vistas comparten servicio y validador.** Las reglas no se duplican: un filtro inválido da 400 tanto en `/api/reservas` como en `/reservas`.
- **Sin framework, contenedor de dependencias ni interfaces innecesarias.** Las dependencias se crean a mano en `index.php`. La única interfaz, `Response`, existe porque hay dos implementaciones reales (JSON y HTML).

**Por qué MySQL/MariaDB.** Los datos son claramente relacionales (una reserva tiene muchos eventos) y la base de datos ayuda a mantener la integridad: clave foránea, `ENUM` para los estados, `CHECK (fecha_salida > fecha_entrada)`, `DECIMAL(10,2)` para el importe y transacciones. Además, es la base de datos que usa MisterPlan.

**Otras decisiones**

| Decisión | Motivo |
|---|---|
| El importe se maneja como texto en PHP y en JS | `DECIMAL` llega de PDO como texto; así nunca pasa por `float` ni se redondea |
| `SELECT … FOR UPDATE` al cambiar de estado | Evita que dos peticiones simultáneas dejen un estado incoherente (probado con un test de dos procesos) |
| Fechas con hora guardadas en UTC; se muestran en hora de Madrid | Funciona igual en local y en el servidor, sea cual sea su zona horaria. Las horas del `.sql` de ejemplo se interpretan como UTC |
| 400 frente a 422 | 400 si la petición no se puede interpretar; 422 si se entiende pero los datos no cumplen las reglas, con el error de cada campo |
| `fecha_entrada` en el pasado permitida | Es una herramienta interna: se pueden registrar reservas tardías |
| Twig pinta la carga inicial y JavaScript repinta al filtrar | Las vistas se renderizan en el servidor (y funcionan sin JS); el JS clona un `<template>` de Twig, así la estructura de la fila solo está en un sitio |
| Formato español con los filtros del núcleo de Twig | `number_format` y `date` bastan; no hace falta añadir `twig/intl-extra` |

## Seguridad

La API recibe datos de fuera, así que no se fía de nada que llegue en la petición.

| Riesgo | Medida | Dónde | Comprobación |
|---|---|---|---|
| Inyección SQL | Todas las consultas son preparadas, con preparadas nativas (`ATTR_EMULATE_PREPARES = false`); ningún valor del usuario se concatena en el SQL | `ReservaRepository`, `Database` | Revisión de código; tests de filtros contra MySQL; id `1 OR 1=1` → 400 |
| Comodines en la búsqueda | `%` y `_` del usuario se escapan (`LIKE … ESCAPE '!'`) | `ReservaRepository::escaparLike()` | Test: buscar `%` solo devuelve la reserva que lo contiene |
| Asignación masiva | Lista cerrada de campos: enviar `estado` o `id` → 422. El `INSERT` solo usa 7 columnas fijas | `ReservaValidator`, `ReservaRepository::insert()` | Tests |
| Datos inválidos | Validación en el servidor: tipos JSON estrictos, longitudes iguales a las del SQL, fechas reales, salida > entrada, email e importe | `ReservaValidator` | 45 tests unitarios |
| Estados incoherentes | Máquina de estados; una transición no permitida → 409 sin modificar nada | `ReservaService` | Tests |
| Condiciones de carrera | `SELECT … FOR UPDATE` dentro de la transacción | `ReservaService::cambiarEstado()` | Test con dos procesos (falla si se quita el `FOR UPDATE`) |
| Datos a medias | Reserva y evento en la misma transacción; rollback ante cualquier error | `ReservaService::enTransaccion()` | Test que fuerza el fallo del segundo `INSERT` |
| XSS | Autoescape de Twig activo y **sin `|raw`**. En JavaScript, los datos se insertan con `textContent`, nunca con `innerHTML` | Plantillas, `public/assets/js/` | Tests de plantillas con `<img src=x onerror=…>`; prueba en navegador |
| XSS (segunda barrera) | Cabecera `Content-Security-Policy: default-src 'self'`: el navegador no ejecuta scripts en línea ni de otros dominios | `HtmlResponse` | Test: las plantillas no contienen JS en línea |
| Fuga de información | Manejo de errores centralizado; un 500 muestra un mensaje genérico y el detalle va al log. Sin `display_errors` ni cabecera `X-Powered-By` | `public/index.php`, `ManejadorErrores` | Tests; comprobado con la base de datos caída |
| Peticiones abusivas o mal formadas | Cuerpo máximo de 16 KB (413), profundidad JSON limitada, `Content-Type: application/json` obligatorio en las escrituras (415) | `Request` | Smoke test |
| Métodos no previstos | Solo los registrados; el resto → 405 con `Allow` | `Router` | Tests |
| CSRF | No hay sesiones ni cookies, así que no hay credencial que otra web pueda aprovechar. Además, las escrituras exigen JSON, que un formulario de otro sitio no puede enviar sin una petición previa `OPTIONS` que la API rechaza (405, sin cabeceras CORS) | `Request`, `Router` | Si se añadiera autenticación con cookies, haría falta un token CSRF |
| Credenciales | `.env` fuera de `public/` y de Git; el usuario de la base de datos solo tiene `SELECT, INSERT, UPDATE` | `.gitignore`, `.env.example` | `DROP` rechazado (ERROR 1142) |
| Integridad | `ENUM`, `CHECK`, `DECIMAL(10,2)` y clave foránea `ON DELETE RESTRICT` | `database/reservas.sql` | Comprobado con inserciones inválidas |
| Archivos del proyecto expuestos | Solo `public/` es accesible por la web; sin listado de carpetas | `public/.htaccess` | Comprobado con Apache |

## Limitaciones y qué haría con más tiempo

**Límites conocidos**

- **Sin autenticación ni límite de peticiones.** Es una herramienta interna de demostración con datos ficticios.
- **No se impide solapar reservas del mismo alojamiento.** El enunciado no lo pide y el alojamiento es un campo de texto, no una entidad.
- **Sin paginación:** el listado devuelve todas las reservas que cumplen los filtros.
- **Doble envío:** la interfaz desactiva el botón durante la petición, pero un reintento de red podría crear dos reservas.
- **Sin caché de plantillas Twig.**
- **`HEAD` no está soportado** en las rutas GET (responde 405).

**Mejoras**

1. Autenticación y roles, con token CSRF si se usan cookies de sesión.
2. Tabla `alojamiento` y control de solapes al crear (409) con bloqueo por alojamiento.
3. Paginación en la API y en el listado.
4. Clave de idempotencia en `POST` para que un reintento no duplique la reserva.
5. Respuestas de error según RFC 9457 (`application/problem+json`).
6. Caché de plantillas Twig y OPcache en producción.
7. Integración continua (GitHub Actions) que ejecute PHPUnit contra un MySQL en cada *push*.
8. Registro estructurado con un identificador por petición.

## Escalabilidad: 1.000 reservas por hora

**Cuánta carga es.** 1.000 reservas por hora son unas **0,3 escrituras por segundo** de media. Aunque el pico fuera diez veces mayor, serían 3 transacciones por segundo, cada una con dos `INSERT` pequeños. MySQL lo soporta sin problemas en un servidor modesto. **El cuello de botella no estaría en las escrituras, sino en lo que crece con ellas**: unas 24.000 reservas al día y más de 8 millones al año.

**Qué mediría antes de cambiar nada:** el *slow query log* de MySQL, `EXPLAIN` de las consultas del listado, la latencia p95 de cada endpoint y el número de conexiones simultáneas. Optimizaría con datos, no por intuición.

**Qué cambiaría, por orden de prioridad**

1. **Paginación.** Es lo primero que se rompe: con millones de filas, `GET /api/reservas` sin límite devolvería respuestas enormes. Usaría paginación por cursor (`WHERE (fecha_entrada, id) > (?, ?) ORDER BY fecha_entrada, id LIMIT 50`), que no se degrada como `OFFSET`.
2. **Índices revisados con `EXPLAIN`.** Ya existen `(estado, fecha_entrada)`, `(fecha_entrada, fecha_salida)` y `(reserva_id, fecha_creacion)` para el historial. El punto débil es la búsqueda por nombre: `LIKE '%texto%'` no puede usar el índice `nombre_huesped` y recorre la tabla entera. Opciones: búsqueda por prefijo (`LIKE 'texto%'`, que sí usa el índice), un índice `FULLTEXT` o, a gran escala, un motor de búsqueda aparte.
3. **Transacciones cortas.** Ya lo son: el `FOR UPDATE` bloquea una sola fila durante dos sentencias. No hay bloqueos de tabla.
4. **Tareas secundarias fuera de la petición.** Si se añadieran emails de confirmación o avisos a otros sistemas, irían a una cola (por ejemplo, una tabla de trabajos o Redis) procesada por un *worker*, para no alargar la respuesta ni perder la reserva si falla el envío.
5. **Conexiones y servidor.** PHP-FPM con OPcache y caché de Twig, y un número de procesos ajustado a lo que admite MySQL (`max_connections`). La aplicación no guarda estado entre peticiones, así que se puede escalar en horizontal poniendo varios servidores detrás de un balanceador.
6. **Solo si las lecturas lo justifican:** réplicas de lectura para el listado y caché de corta duración para consultas repetidas. Con 1.000 reservas por hora, probablemente no haría falta.
7. **Monitorización y copias de seguridad:** métricas de latencia y errores, alertas, *backups* automáticos y una política de archivado para las reservas antiguas.

**Lo que no cambiaría:** la arquitectura. Separar controlador, servicio y repositorio permite añadir paginación, caché o colas sin reescribir la lógica de negocio.