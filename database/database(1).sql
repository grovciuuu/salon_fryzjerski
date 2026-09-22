-- =====================================================================
-- System rezerwacji usług — SALON FRYZJERSKI
-- Etap 1: Projekt systemu, GitHub i baza danych
-- Silnik: MySQL 8+ / MariaDB 10.4+
-- Kodowanie: utf8mb4
-- =====================================================================
-- Kolejność tabel: users -> service_categories -> services -> employees
-- -> employee_services (N:M) -> employee_availability -> reservations
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Usuwanie tabel w kolejności odwrotnej do zależności (bezpieczne przeładowanie bazy)
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS employee_availability;
DROP TABLE IF EXISTS employee_services;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS service_categories;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- TABELA: users
-- Przechowuje wszystkich użytkowników systemu (klientów, pracowników,
-- administratorów). Rola rozróżniana kolumną `role`.
-- =====================================================================
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name      VARCHAR(50)  NOT NULL,
    last_name       VARCHAR(50)  NOT NULL,
    email           VARCHAR(150) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,      -- wynik password_hash()
    phone           VARCHAR(20)  NULL,
    role            ENUM('client', 'employee', 'admin') NOT NULL DEFAULT 'client',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_users_email UNIQUE (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TABELA: service_categories
-- Kategorie usług fryzjerskich (np. strzyżenie, koloryzacja, stylizacja)
-- =====================================================================
CREATE TABLE service_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    description     TEXT         NULL,

    CONSTRAINT uq_category_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TABELA: services
-- Konkretne usługi oferowane przez salon, przypisane do kategorii.
-- Relacja 1:N -> jedna kategoria ma wiele usług.
-- =====================================================================
CREATE TABLE services (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    description     TEXT         NULL,
    duration        SMALLINT UNSIGNED NOT NULL COMMENT 'czas trwania w minutach',
    price           DECIMAL(8,2) NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,

    CONSTRAINT fk_services_category
        FOREIGN KEY (category_id) REFERENCES service_categories(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_services_duration CHECK (duration > 0),
    CONSTRAINT chk_services_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TABELA: employees
-- Rozszerza konto użytkownika o dane specyficzne dla fryzjera.
-- Relacja 1:1 z users (nie duplikujemy imienia/nazwiska/e-maila).
-- =====================================================================
CREATE TABLE employees (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    bio             TEXT         NULL COMMENT 'krótki opis / specjalizacja fryzjera',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,

    CONSTRAINT fk_employees_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT uq_employees_user UNIQUE (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TABELA: employee_services  (relacja N:M)
-- Który fryzjer wykonuje które usługi.
-- =====================================================================
CREATE TABLE employee_services (
    employee_id     INT UNSIGNED NOT NULL,
    service_id      INT UNSIGNED NOT NULL,

    PRIMARY KEY (employee_id, service_id),

    CONSTRAINT fk_empserv_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_empserv_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TABELA: employee_availability
-- Dostępność fryzjera w danym dniu tygodnia (mechanizm godzin pracy).
-- =====================================================================
CREATE TABLE employee_availability (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT UNSIGNED NOT NULL,
    day_of_week     TINYINT UNSIGNED NOT NULL COMMENT '1=poniedziałek ... 7=niedziela',
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,

    CONSTRAINT fk_availability_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_availability_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_availability_time CHECK (end_time > start_time),

    -- fryzjer nie może mieć dwóch identycznych wpisów godzin w tym samym dniu
    CONSTRAINT uq_availability_slot UNIQUE (employee_id, day_of_week, start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TABELA: reservations
-- Rezerwacje klientów. Powiązana z klientem, fryzjerem i usługą.
-- =====================================================================
CREATE TABLE reservations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL COMMENT 'klient',
    employee_id         INT UNSIGNED NOT NULL,
    service_id          INT UNSIGNED NOT NULL,
    reservation_date    DATE         NOT NULL,
    start_time          TIME         NOT NULL,
    end_time            TIME         NOT NULL,
    status              ENUM('pending', 'confirmed', 'completed', 'cancelled')
                             NOT NULL DEFAULT 'pending',
    comment             TEXT         NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_reservations_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_reservations_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_reservations_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_reservations_time CHECK (end_time > start_time),

    -- przyspiesza sprawdzanie konfliktów terminów danego fryzjera
    INDEX idx_reservations_employee_date (employee_id, reservation_date),
    INDEX idx_reservations_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- DANE TESTOWE
-- Konta potrzebne do zalogowania i przetestowania systemu (README).
-- Hasło testowe dla WSZYSTKICH kont poniżej: haslo123
-- Hashe wygenerowane algorytmem bcrypt ($2b$) — password_verify() w PHP
-- poprawnie zweryfikuje hasło 'haslo123' względem tych wartości.
-- =====================================================================

INSERT INTO users (first_name, last_name, email, password_hash, phone, role) VALUES
('Anna',   'Kowalska',  'admin@salon.pl',    '$2b$12$A3mmLhRY4DrG7htm78ZyWuWx0HmE.Zb4oVkru8837kr89l1dAf7R2', '600100100', 'admin'),
('Marta',  'Nowak',     'marta.fryzjer@salon.pl', '$2b$12$48NQWmbAKk48mj4543wtg.8p9J96CsiKCm9H8cxAL9NG/XnENGVGO', '600200200', 'employee'),
('Kamil',  'Wiśniewski','kamil.fryzjer@salon.pl', '$2b$12$xhPQtJ5JBvzObMXLJzvpmuLKrHX9UKjECjGSuLYFLVBwYL4maP7Su', '600200201', 'employee'),
('Julia',  'Zielińska', 'julia.klient@example.com', '$2b$12$fCFP8rxoc8rs6rve7Ptbx.Ax7BgmaPmUzK4GdeSxXQoI1SBXQlmHa', '600300300', 'client');

INSERT INTO employees (user_id, bio) VALUES
(2, 'Specjalistka od koloryzacji i strzyżenia damskiego, 8 lat doświadczenia.'),
(3, 'Barber — strzyżenie i stylizacja brody, strzyżenie męskie.');

INSERT INTO service_categories (name, description) VALUES
('Strzyżenie',   'Strzyżenie damskie, męskie i dziecięce'),
('Koloryzacja',  'Farbowanie, pasemka, balejaż'),
('Stylizacja',   'Modelowanie, upięcia okolicznościowe'),
('Pielęgnacja',  'Zabiegi regenerujące włosy i skórę głowy');

INSERT INTO services (category_id, name, description, duration, price) VALUES
(1, 'Strzyżenie damskie',    'Strzyżenie z myciem i modelowaniem', 60, 90.00),
(1, 'Strzyżenie męskie',     'Strzyżenie klasyczne lub maszynką', 30, 50.00),
(2, 'Balejaż',               'Rozjaśnianie pasm metodą balejażu', 150, 280.00),
(2, 'Farbowanie jednolite',  'Farbowanie całej długości włosów', 90, 150.00),
(3, 'Upięcie okolicznościowe','Stylizacja na wesele lub imprezę', 60, 120.00),
(4, 'Zabieg regenerujący',   'Kuracja odżywcza na włosy zniszczone', 45, 100.00);

INSERT INTO employee_services (employee_id, service_id) VALUES
(1, 1), (1, 3), (1, 4), (1, 5), (1, 6),  -- Marta: strzyżenie damskie, koloryzacja, stylizacja, pielęgnacja
(2, 2), (2, 6);                          -- Kamil: strzyżenie męskie, pielęgnacja

INSERT INTO employee_availability (employee_id, day_of_week, start_time, end_time) VALUES
(1, 1, '09:00:00', '17:00:00'),  -- Marta: poniedziałek
(1, 2, '09:00:00', '17:00:00'),
(1, 3, '09:00:00', '17:00:00'),
(1, 4, '09:00:00', '17:00:00'),
(1, 5, '09:00:00', '15:00:00'),
(2, 2, '11:00:00', '19:00:00'),  -- Kamil: wtorek-sobota
(2, 3, '11:00:00', '19:00:00'),
(2, 4, '11:00:00', '19:00:00'),
(2, 5, '11:00:00', '19:00:00'),
(2, 6, '10:00:00', '14:00:00');

INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time, status, comment) VALUES
(4, 1, 1, '2026-10-05', '10:00:00', '11:00:00', 'confirmed', 'Pierwsza wizyta'),
(4, 2, 2, '2026-10-06', '12:00:00', '12:30:00', 'pending', NULL);
