-- =====================================================================
--  SKSVMACET Bus Pass & Route Management System
--  Database schema  (MySQL 8.x  /  MariaDB 10.4+ as shipped with XAMPP)
--
--  Import order:  1) schema.sql   2) seed.sql
--  This script DROPS and recreates the database `sksvmacet_buspass`.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS sksvmacet_buspass;
CREATE DATABASE sksvmacet_buspass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sksvmacet_buspass;

-- ---------------------------------------------------------------------
-- 1. USERS  (one row per login account, any role)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role            ENUM('student','driver','admin') NOT NULL,
    email           VARCHAR(150) NOT NULL,
    username        VARCHAR(60)  NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(120) NOT NULL,
    phone           VARCHAR(15)  NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. ROLE PROFILES
-- ---------------------------------------------------------------------
CREATE TABLE students (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    usn                VARCHAR(20)  NOT NULL,
    branch             ENUM('CSE','ISE','ECE','EEE','Mechanical','Civil','AI/ML','Other') NOT NULL,
    year_of_study      TINYINT UNSIGNED NOT NULL,
    academic_year      VARCHAR(9)   NOT NULL,          -- e.g. 2026-2027
    address            VARCHAR(255) NOT NULL,
    emergency_contact  VARCHAR(15)  NOT NULL,
    profile_photo      VARCHAR(80)  NULL,              -- random file name inside uploads/profiles
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_user (user_id),
    UNIQUE KEY uq_students_usn (usn),
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE drivers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    driver_code     VARCHAR(20)  NOT NULL,             -- e.g. DRV001
    license_number  VARCHAR(30)  NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drivers_user (user_id),
    UNIQUE KEY uq_drivers_code (driver_code),
    CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admins (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    designation  VARCHAR(80) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_user (user_id),
    CONSTRAINT fk_admins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. ROUTES, STOPS, FEES
-- ---------------------------------------------------------------------
CREATE TABLE routes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_code     VARCHAR(20)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    origin         VARCHAR(80)  NOT NULL,
    destination    VARCHAR(80)  NOT NULL,
    is_round_trip  TINYINT(1)   NOT NULL DEFAULT 1,
    description    VARCHAR(255) NULL,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_routes_code (route_code)
) ENGINE=InnoDB;

CREATE TABLE bus_stops (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_id          INT UNSIGNED NOT NULL,
    stop_code         VARCHAR(20)  NOT NULL,
    name              VARCHAR(100) NOT NULL,
    latitude          DECIMAL(10,7) NOT NULL,
    longitude         DECIMAL(10,7) NOT NULL,
    stop_order        SMALLINT UNSIGNED NOT NULL,
    direction         ENUM('onward','return','both') NOT NULL DEFAULT 'both',
    distance_km       DECIMAL(7,2) NOT NULL DEFAULT 0,   -- distance from route origin, used for fare
    est_arrival_time  TIME NULL,
    status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stops_code (stop_code),
    KEY idx_stops_route_order (route_id, stop_order),
    CONSTRAINT fk_stops_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE pass_fees (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_id      INT UNSIGNED NOT NULL,
    duration      ENUM('semester','annual') NOT NULL,
    base_fee      DECIMAL(10,2) NOT NULL,
    per_km_rate   DECIMAL(8,2)  NOT NULL DEFAULT 0,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fee_route_duration (route_id, duration),
    CONSTRAINT fk_fees_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Driver may *request* a new stop on their own route; admin approves.
CREATE TABLE stop_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id    INT UNSIGNED NOT NULL,
    route_id     INT UNSIGNED NOT NULL,
    name         VARCHAR(100) NOT NULL,
    latitude     DECIMAL(10,7) NOT NULL,
    longitude    DECIMAL(10,7) NOT NULL,
    note         VARCHAR(255) NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by  INT UNSIGNED NULL,
    reviewed_at  DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stopreq_status (status),
    CONSTRAINT fk_stopreq_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT fk_stopreq_route  FOREIGN KEY (route_id)  REFERENCES routes(id)  ON DELETE CASCADE,
    CONSTRAINT fk_stopreq_admin  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. BUSES + DRIVER ASSIGNMENTS
-- ---------------------------------------------------------------------
CREATE TABLE buses (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bus_code             VARCHAR(20) NOT NULL,          -- BUS001
    bus_number           VARCHAR(20) NOT NULL,          -- fleet / display number
    registration_number  VARCHAR(20) NOT NULL,          -- KA-26-F-1234
    bus_name             VARCHAR(80) NULL,
    capacity             SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    route_id             INT UNSIGNED NULL,
    status               ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bus_code (bus_code),
    UNIQUE KEY uq_bus_reg (registration_number),
    CONSTRAINT fk_bus_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- History of driver<->bus assignments. The generated columns + unique keys
-- guarantee at most ONE active assignment per driver and per bus.
CREATE TABLE driver_bus_assignments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id      INT UNSIGNED NOT NULL,
    bus_id         INT UNSIGNED NOT NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    assigned_by    INT UNSIGNED NULL,
    assigned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unassigned_at  DATETIME NULL,
    active_driver  INT UNSIGNED GENERATED ALWAYS AS (IF(is_active = 1, driver_id, NULL)) STORED,
    active_bus     INT UNSIGNED GENERATED ALWAYS AS (IF(is_active = 1, bus_id, NULL)) STORED,
    UNIQUE KEY uq_one_active_driver (active_driver),
    UNIQUE KEY uq_one_active_bus (active_bus),
    CONSTRAINT fk_assign_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_bus    FOREIGN KEY (bus_id)    REFERENCES buses(id)   ON DELETE CASCADE,
    CONSTRAINT fk_assign_admin  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. BUS-PASS APPLICATIONS, PAYMENTS, PASSES
-- ---------------------------------------------------------------------
CREATE TABLE bus_pass_applications (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_no       VARCHAR(20) NOT NULL,
    student_id           INT UNSIGNED NOT NULL,
    route_id             INT UNSIGNED NOT NULL,
    boarding_stop_id     INT UNSIGNED NOT NULL,
    destination_stop_id  INT UNSIGNED NOT NULL,
    academic_year        VARCHAR(9) NOT NULL,
    duration             ENUM('semester','annual','monthly') NOT NULL,
    distance_km          DECIMAL(7,2) NOT NULL,
    fee_amount           DECIMAL(10,2) NOT NULL,
    application_type     ENUM('new','renewal') NOT NULL DEFAULT 'new',
    previous_pass_id     INT UNSIGNED NULL,
    status               ENUM('payment_pending','payment_submitted','pending_approval',
                              'approved','rejected','cancelled') NOT NULL DEFAULT 'payment_pending',
    admin_remarks        VARCHAR(255) NULL,
    reviewed_by          INT UNSIGNED NULL,
    reviewed_at          DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_no (application_no),
    KEY idx_app_status (status),
    KEY idx_app_student (student_id, status),
    CONSTRAINT fk_app_student  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_app_route    FOREIGN KEY (route_id) REFERENCES routes(id),
    CONSTRAINT fk_app_board    FOREIGN KEY (boarding_stop_id) REFERENCES bus_stops(id),
    CONSTRAINT fk_app_dest     FOREIGN KEY (destination_stop_id) REFERENCES bus_stops(id),
    CONSTRAINT fk_app_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Exactly one payment record per application. A rejected payment is
-- re-submitted by updating the same row (history goes to audit_logs).
CREATE TABLE payments (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id             INT UNSIGNED NOT NULL,
    application_id         INT UNSIGNED NOT NULL,
    amount                 DECIMAL(10,2) NOT NULL,
    payment_method         VARCHAR(20) NOT NULL DEFAULT 'UPI',
    transaction_reference  VARCHAR(30) NULL,            -- UTR; UNIQUE => duplicate UTRs rejected
    payment_screenshot     VARCHAR(80) NULL,            -- random file name inside uploads/payment
    payment_date           DATE NULL,
    status                 ENUM('pending','submitted','verified','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason       VARCHAR(255) NULL,
    submitted_at           DATETIME NULL,
    verified_by            INT UNSIGNED NULL,
    verified_at            DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pay_application (application_id),
    UNIQUE KEY uq_pay_utr (transaction_reference),
    KEY idx_pay_status (status),
    CONSTRAINT fk_pay_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_app     FOREIGN KEY (application_id) REFERENCES bus_pass_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_admin   FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE bus_passes (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pass_number          VARCHAR(20) NOT NULL,          -- BP2026000001
    application_id       INT UNSIGNED NOT NULL,
    student_id           INT UNSIGNED NOT NULL,
    route_id             INT UNSIGNED NOT NULL,
    bus_id               INT UNSIGNED NULL,
    boarding_stop_id     INT UNSIGNED NOT NULL,
    destination_stop_id  INT UNSIGNED NOT NULL,
    academic_year        VARCHAR(9) NOT NULL,
    valid_from           DATE NOT NULL,
    valid_until          DATE NOT NULL,
    status               ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
    verification_token   CHAR(64) NOT NULL,             -- random_bytes(32), hex; only thing in the QR
    issued_by            INT UNSIGNED NULL,
    issued_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pass_number (pass_number),
    UNIQUE KEY uq_pass_application (application_id),
    UNIQUE KEY uq_pass_token (verification_token),
    KEY idx_pass_student (student_id, status),
    KEY idx_pass_bus (bus_id, status),
    CONSTRAINT fk_pass_app     FOREIGN KEY (application_id) REFERENCES bus_pass_applications(id),
    CONSTRAINT fk_pass_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_pass_route   FOREIGN KEY (route_id) REFERENCES routes(id),
    CONSTRAINT fk_pass_bus     FOREIGN KEY (bus_id) REFERENCES buses(id) ON DELETE SET NULL,
    CONSTRAINT fk_pass_board   FOREIGN KEY (boarding_stop_id) REFERENCES bus_stops(id),
    CONSTRAINT fk_pass_dest    FOREIGN KEY (destination_stop_id) REFERENCES bus_stops(id),
    CONSTRAINT fk_pass_issuer  FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE bus_pass_applications
    ADD CONSTRAINT fk_app_prev_pass FOREIGN KEY (previous_pass_id) REFERENCES bus_passes(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 6. TRIPS + GPS
-- ---------------------------------------------------------------------
CREATE TABLE trips (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bus_id            INT UNSIGNED NOT NULL,
    driver_id         INT UNSIGNED NOT NULL,
    route_id          INT UNSIGNED NOT NULL,
    gps_mode          ENUM('real','simulation') NOT NULL DEFAULT 'real',
    status            ENUM('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'active',
    started_at        DATETIME NULL,
    ended_at          DATETIME NULL,
    start_latitude    DECIMAL(10,7) NULL,
    start_longitude   DECIMAL(10,7) NULL,
    end_latitude      DECIMAL(10,7) NULL,
    end_longitude     DECIMAL(10,7) NULL,
    last_location_at  DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- at most one ACTIVE trip per driver and per bus
    active_driver     INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'active', driver_id, NULL)) STORED,
    active_bus        INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'active', bus_id, NULL)) STORED,
    UNIQUE KEY uq_trip_active_driver (active_driver),
    UNIQUE KEY uq_trip_active_bus (active_bus),
    KEY idx_trip_status (status),
    CONSTRAINT fk_trip_bus    FOREIGN KEY (bus_id) REFERENCES buses(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_route  FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bus_locations (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id       INT UNSIGNED NOT NULL,
    bus_id        INT UNSIGNED NOT NULL,
    driver_id     INT UNSIGNED NOT NULL,
    latitude      DECIMAL(10,7) NOT NULL,
    longitude     DECIMAL(10,7) NOT NULL,
    accuracy_m    DECIMAL(8,2) NULL,
    speed_mps     DECIMAL(6,2) NULL,
    heading       DECIMAL(5,1) NULL,
    is_simulated  TINYINT(1) NOT NULL DEFAULT 0,
    recorded_at   DATETIME NOT NULL,                    -- device timestamp (validated)
    received_at   DATETIME NOT NULL,                    -- server time
    KEY idx_loc_trip (trip_id, id),
    KEY idx_loc_bus (bus_id, id),
    CONSTRAINT fk_loc_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_loc_bus  FOREIGN KEY (bus_id) REFERENCES buses(id) ON DELETE CASCADE,
    CONSTRAINT fk_loc_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. NOTIFICATIONS, SETTINGS, AUDIT, SECURITY
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(120) NOT NULL,
    message     VARCHAR(500) NOT NULL,
    link        VARCHAR(200) NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Central key/value configuration. payment_qr_path lives here and ONLY here.
CREATE TABLE settings (
    setting_key    VARCHAR(64) PRIMARY KEY,
    setting_value  TEXT NULL,
    updated_by     INT UNSIGNED NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    user_role    VARCHAR(10) NULL,
    action       VARCHAR(64) NOT NULL,
    entity_type  VARCHAR(40) NULL,
    entity_id    INT UNSIGNED NULL,
    details      TEXT NULL,                              -- JSON
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_action (action),
    KEY idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier    VARCHAR(150) NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME NOT NULL,
    KEY idx_login_ident (identifier, attempted_at),
    KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

-- Generic fixed-window rate limiter (registration, verify-pass, APIs).
CREATE TABLE rate_limits (
    bucket        VARCHAR(120) PRIMARY KEY,
    hits          INT UNSIGNED NOT NULL DEFAULT 0,
    window_start  INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
