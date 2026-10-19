-- =============================================================
-- Prueba técnica MisterPlan — Reservas de alojamiento
-- Esquema (DDL) + datos de ejemplo.
-- Compatible con MySQL 8 y MariaDB 10.4+.
-- Importar sobre una base de datos ya creada:
--   mysql -u root misterplan < database/reservas.sql
-- ATENCIÓN: elimina y recrea las tablas (reimportable).
-- =============================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS reserva_evento;
DROP TABLE IF EXISTS reserva;

-- -------------------------------------------------------------
-- Tabla principal
-- -------------------------------------------------------------
CREATE TABLE reserva (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre_huesped     VARCHAR(120)  NOT NULL,
    email_huesped      VARCHAR(254)  NOT NULL,
    nombre_alojamiento VARCHAR(150)  NOT NULL,
    fecha_entrada      DATE          NOT NULL,
    fecha_salida       DATE          NOT NULL,
    estado             ENUM('pendiente','confirmada','cancelada') NOT NULL DEFAULT 'pendiente',
    importe            DECIMAL(10,2) NOT NULL,
    notas              VARCHAR(1000) NULL,
    fecha_creacion     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reserva_estado_entrada (estado, fecha_entrada),
    KEY idx_reserva_fechas (fecha_entrada, fecha_salida),
    KEY idx_reserva_nombre (nombre_huesped),
    CONSTRAINT chk_reserva_fechas  CHECK (fecha_salida > fecha_entrada),
    CONSTRAINT chk_reserva_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Historial de eventos (timeline)
-- -------------------------------------------------------------
CREATE TABLE reserva_evento (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reserva_id     INT UNSIGNED NOT NULL,
    tipo           ENUM('creacion','confirmacion','cancelacion') NOT NULL,
    descripcion    VARCHAR(255) NOT NULL,
    fecha_creacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_evento_reserva_fecha (reserva_id, fecha_creacion),
    CONSTRAINT fk_evento_reserva
        FOREIGN KEY (reserva_id) REFERENCES reserva (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Datos de ejemplo
-- -------------------------------------------------------------
START TRANSACTION;

INSERT INTO reserva
    (id, nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, fecha_salida, estado, importe, notas, fecha_creacion)
VALUES
    (1,  'María José Fernández', 'mariajose.fernandez@example.com', 'Apartamentos Triana Río',      '2026-11-06', '2026-11-09', 'confirmada',  285.00, 'Llegada tarde, sobre las 22:00',       '2026-10-01 10:15:00'),
    (2,  'Javier Núñez Ortega',  'javier.nunez@example.com',        'Hotel Mirador de la Giralda',  '2026-11-06', '2026-11-08', 'pendiente',   198.50, NULL,                                   '2026-10-12 09:30:00'),
    (3,  'Lucía Peña Romero',    'lucia.pena@example.com',          'Casa Rural Sierra de Aracena', '2026-11-13', '2026-11-15', 'cancelada',   320.00, 'Cancelada por la huésped',             '2026-10-03 18:45:00'),
    (4,  'John O''Connor',       'john.oconnor@example.com',        'Hostal Plaza Nueva',           '2026-11-20', '2026-11-23', 'confirmada',  156.00, 'Necesita cuna para bebé',              '2026-10-05 12:00:00'),
    (5,  'Ana Fernández López',  'ana.fernandez@example.com',       'Villa Costa de la Luz',        '2026-12-04', '2026-12-08', 'pendiente',   740.00, NULL,                                   '2026-10-14 16:20:00'),
    (6,  'Carlos Muñoz García',  'carlos.munoz@example.com',        'Apartamentos Triana Río',      '2026-12-04', '2026-12-06', 'cancelada',   190.00, 'Cambio de planes; reembolso completo', '2026-09-28 11:10:00'),
    (7,  'Elena Martín Ibáñez',  'elena.martin@example.com',        'Hotel Mirador de la Giralda',  '2026-12-23', '2026-12-27', 'confirmada',  612.80, 'Cena de Nochebuena incluida',          '2026-10-08 20:05:00'),
    (8,  'Pablo Fernández Ruiz', 'pablo.fernandez@example.com',     'Hostal Plaza Nueva',           '2026-12-30', '2027-01-02', 'pendiente',   225.00, NULL,                                   '2026-10-16 08:40:00'),
    (9,  'Sofía Álvarez Torres', 'sofia.alvarez@example.com',       'Casa Rural Sierra de Aracena', '2026-10-09', '2026-10-12', 'confirmada',  410.00, 'Estancia ya finalizada',               '2026-09-15 13:25:00'),
    (10, 'Miguel Ángel Ruiz',    'miguelangel.ruiz@example.com',    'Villa Costa de la Luz',        '2026-10-23', '2026-10-30', 'confirmada', 1250.00, 'Grupo de 6 personas',                  '2026-09-20 17:00:00'),
    (11, 'Laura Gómez Sanz',     'laura.gomez@example.com',         'Apartamentos Triana Río',      '2026-11-08', '2026-11-11', 'pendiente',   297.00, NULL,                                   '2026-10-17 21:15:00'),
    (12, 'Fernando Díaz Peña',   'fernando.diaz@example.com',       'Hotel Mirador de la Giralda',  '2026-11-27', '2026-11-29', 'cancelada',   176.40, NULL,                                   '2026-10-10 10:00:00');

INSERT INTO reserva_evento (reserva_id, tipo, descripcion, fecha_creacion) VALUES
    (1,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-01 10:15:00'),
    (1,  'confirmacion', 'Estado cambiado de pendiente a confirmada',  '2026-10-02 09:00:00'),
    (2,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-12 09:30:00'),
    (3,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-03 18:45:00'),
    (3,  'cancelacion',  'Estado cambiado de pendiente a cancelada',   '2026-10-07 11:20:00'),
    (4,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-05 12:00:00'),
    (4,  'confirmacion', 'Estado cambiado de pendiente a confirmada',  '2026-10-05 17:30:00'),
    (5,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-14 16:20:00'),
    (6,  'creacion',     'Reserva creada en estado pendiente',         '2026-09-28 11:10:00'),
    (6,  'confirmacion', 'Estado cambiado de pendiente a confirmada',  '2026-09-29 10:00:00'),
    (6,  'cancelacion',  'Estado cambiado de confirmada a cancelada',  '2026-10-11 19:45:00'),
    (7,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-08 20:05:00'),
    (7,  'confirmacion', 'Estado cambiado de pendiente a confirmada',  '2026-10-09 08:15:00'),
    (8,  'creacion',     'Reserva creada en estado pendiente',         '2026-10-16 08:40:00'),
    (9,  'creacion',     'Reserva creada en estado pendiente',         '2026-09-15 13:25:00'),
    (9,  'confirmacion', 'Estado cambiado de pendiente a confirmada',  '2026-09-16 09:10:00'),
    (10, 'creacion',     'Reserva creada en estado pendiente',         '2026-09-20 17:00:00'),
    (10, 'confirmacion', 'Estado cambiado de pendiente a confirmada',  '2026-09-21 12:30:00'),
    (11, 'creacion',     'Reserva creada en estado pendiente',         '2026-10-17 21:15:00'),
    (12, 'creacion',     'Reserva creada en estado pendiente',         '2026-10-10 10:00:00'),
    (12, 'cancelacion',  'Estado cambiado de pendiente a cancelada',   '2026-10-13 16:00:00');

COMMIT;
