SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS salon_fryzjerski;
CREATE DATABASE salon_fryzjerski
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_polish_ci;

USE salon_fryzjerski;

CREATE TABLE uzytkownicy (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    imie              VARCHAR(50)  NOT NULL,
    nazwisko          VARCHAR(50)  NOT NULL,
    email             VARCHAR(150) NOT NULL,
    haslo             VARCHAR(255) NOT NULL,
    telefon           VARCHAR(20)  NOT NULL,
    rola              ENUM('klient', 'pracownik', 'admin') NOT NULL DEFAULT 'klient',
    aktywny           TINYINT(1)   NOT NULL DEFAULT 1,
    data_utworzenia   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_uzytkownicy_email UNIQUE (email)
) ENGINE=InnoDB;

CREATE INDEX idx_uzytkownicy_rola ON uzytkownicy (rola);

CREATE TABLE kategorie_uslug (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nazwa   VARCHAR(100) NOT NULL,
    opis    TEXT NULL,

    CONSTRAINT uq_kategoria_nazwa UNIQUE (nazwa)
) ENGINE=InnoDB;

CREATE TABLE uslugi (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kategoria_id    INT UNSIGNED NOT NULL,
    nazwa           VARCHAR(150) NOT NULL,
    opis            TEXT NULL,
    czas_trwania    SMALLINT UNSIGNED NOT NULL,
    cena            DECIMAL(8,2) NOT NULL,
    aktywna         TINYINT(1) NOT NULL DEFAULT 1,

    CONSTRAINT fk_uslugi_kategoria
        FOREIGN KEY (kategoria_id) REFERENCES kategorie_uslug(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    CONSTRAINT chk_uslugi_czas CHECK (czas_trwania > 0),
    CONSTRAINT chk_uslugi_cena CHECK (cena >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_uslugi_kategoria ON uslugi (kategoria_id);
CREATE INDEX idx_uslugi_aktywna ON uslugi (aktywna);

CREATE TABLE pracownicy (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uzytkownik_id   INT UNSIGNED NOT NULL,
    opis            TEXT NULL,
    aktywny         TINYINT(1) NOT NULL DEFAULT 1,

    CONSTRAINT uq_pracownicy_uzytkownik UNIQUE (uzytkownik_id),
    CONSTRAINT fk_pracownicy_uzytkownik
        FOREIGN KEY (uzytkownik_id) REFERENCES uzytkownicy(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pracownicy_uslugi (
    pracownik_id    INT UNSIGNED NOT NULL,
    usluga_id       INT UNSIGNED NOT NULL,

    PRIMARY KEY (pracownik_id, usluga_id),

    CONSTRAINT fk_pu_pracownik
        FOREIGN KEY (pracownik_id) REFERENCES pracownicy(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_pu_usluga
        FOREIGN KEY (usluga_id) REFERENCES uslugi(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_pu_usluga ON pracownicy_uslugi (usluga_id);

CREATE TABLE dostepnosc_pracownikow (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pracownik_id    INT UNSIGNED NOT NULL,
    dzien_tygodnia  TINYINT UNSIGNED NOT NULL,
    godzina_od      TIME NOT NULL,
    godzina_do      TIME NOT NULL,

    CONSTRAINT fk_dostepnosc_pracownik
        FOREIGN KEY (pracownik_id) REFERENCES pracownicy(id)
        ON UPDATE CASCADE ON DELETE CASCADE,

    CONSTRAINT chk_dostepnosc_dzien CHECK (dzien_tygodnia BETWEEN 0 AND 6),
    CONSTRAINT chk_dostepnosc_godziny CHECK (godzina_od < godzina_do),
    CONSTRAINT uq_dostepnosc_pracownik_dzien UNIQUE (pracownik_id, dzien_tygodnia)
) ENGINE=InnoDB;

CREATE INDEX idx_dostepnosc_pracownik ON dostepnosc_pracownikow (pracownik_id);

CREATE TABLE rezerwacje (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    klient_id           INT UNSIGNED NOT NULL,
    pracownik_id        INT UNSIGNED NOT NULL,
    usluga_id           INT UNSIGNED NOT NULL,
    data_rezerwacji     DATE NOT NULL,
    godzina_od          TIME NOT NULL,
    godzina_do          TIME NOT NULL,
    status              ENUM('oczekujaca', 'potwierdzona', 'zrealizowana', 'anulowana')
                            NOT NULL DEFAULT 'oczekujaca',
    komentarz           VARCHAR(500) NULL,
    data_utworzenia     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_rezerwacje_klient
        FOREIGN KEY (klient_id) REFERENCES uzytkownicy(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_rezerwacje_pracownik
        FOREIGN KEY (pracownik_id) REFERENCES pracownicy(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_rezerwacje_usluga
        FOREIGN KEY (usluga_id) REFERENCES uslugi(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    CONSTRAINT chk_rezerwacje_godziny CHECK (godzina_od < godzina_do)
) ENGINE=InnoDB;

CREATE INDEX idx_rezerwacje_pracownik_data_godziny
    ON rezerwacje (pracownik_id, data_rezerwacji, godzina_od, godzina_do);

CREATE INDEX idx_rezerwacje_klient ON rezerwacje (klient_id);

CREATE INDEX idx_rezerwacje_status ON rezerwacje (status);

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO uzytkownicy (imie, nazwisko, email, haslo, telefon, rola, aktywny) VALUES
('Anna',    'Kowalska',   'admin@salon.pl',           '$2y$10$92IXUNpkjO0rOQ5byMi.YeCcXsNZa2Xnk3zHchYmVjK1r7X3TrO2G', '600100100', 'admin',     1),
('Marta',   'Nowak',      'marta.fryzjer@salon.pl',   '$2y$10$92IXUNpkjO0rOQ5byMi.YeCcXsNZa2Xnk3zHchYmVjK1r7X3TrO2G', '600200200', 'pracownik', 1),
('Piotr',   'Zielinski',  'piotr.fryzjer@salon.pl',   '$2y$10$92IXUNpkjO0rOQ5byMi.YeCcXsNZa2Xnk3zHchYmVjK1r7X3TrO2G', '600300300', 'pracownik', 1),
('Kasia',   'Wisniewska', 'kasia.klient@poczta.pl',   '$2y$10$92IXUNpkjO0rOQ5byMi.YeCcXsNZa2Xnk3zHchYmVjK1r7X3TrO2G', '600400400', 'klient',    1),
('Tomasz',  'Lewandowski','tomasz.klient@poczta.pl',  '$2y$10$92IXUNpkjO0rOQ5byMi.YeCcXsNZa2Xnk3zHchYmVjK1r7X3TrO2G', '600500500', 'klient',    1),
('Ola',     'Kaminska',   'ola.klient@poczta.pl',     '$2y$10$92IXUNpkjO0rOQ5byMi.YeCcXsNZa2Xnk3zHchYmVjK1r7X3TrO2G', '600600600', 'klient',    1);

INSERT INTO kategorie_uslug (nazwa, opis) VALUES
('Fryzury damskie', 'Strzyzenie, modelowanie i stylizacja wlosow damskich'),
('Fryzury meskie',  'Strzyzenie i stylizacja wlosow meskich, w tym broda'),
('Koloryzacja',     'Farbowanie, rozjasnianie, balayage i refresh koloru'),
('Pielegnacja',     'Zabiegi regenerujace i pielegnacyjne dla wlosow');

INSERT INTO uslugi (kategoria_id, nazwa, opis, czas_trwania, cena, aktywna) VALUES
(1, 'Strzyzenie damskie',       'Strzyzenie z myciem i modelowaniem',        60,  90.00, 1),
(1, 'Modelowanie na zelazko',   'Stylizacja lokow lub prostych wlosow',      45,  70.00, 1),
(2, 'Strzyzenie meskie',        'Strzyzenie maszynka i nozyczkami',          30,  50.00, 1),
(2, 'Strzyzenie brody',         'Formowanie i modelowanie brody',            20,  35.00, 1),
(3, 'Farbowanie jednolite',     'Pelne farbowanie wlosow jednym kolorem',    90, 150.00, 1),
(3, 'Balayage',                 'Rozjasnianie technika balayage',           150, 280.00, 1),
(4, 'Regeneracja keratynowa',   'Zabieg wygladzajaco-regenerujacy',          60, 120.00, 1);

INSERT INTO pracownicy (uzytkownik_id, opis, aktywny) VALUES
(2, 'Specjalistka od koloryzacji i strzyzenia damskiego', 1),
(3, 'Specjalista od strzyzen meskich i stylizacji brody', 1);

INSERT INTO pracownicy_uslugi (pracownik_id, usluga_id) VALUES
(1, 1), (1, 2), (1, 5), (1, 6), (1, 7),
(2, 3), (2, 4);

INSERT INTO dostepnosc_pracownikow (pracownik_id, dzien_tygodnia, godzina_od, godzina_do) VALUES
(1, 1, '09:00:00', '17:00:00'),
(1, 2, '09:00:00', '17:00:00'),
(1, 3, '09:00:00', '17:00:00'),
(1, 4, '09:00:00', '17:00:00'),
(1, 5, '09:00:00', '15:00:00'),
(2, 1, '10:00:00', '18:00:00'),
(2, 2, '10:00:00', '18:00:00'),
(2, 3, '10:00:00', '18:00:00'),
(2, 4, '10:00:00', '18:00:00'),
(2, 6, '09:00:00', '13:00:00');

INSERT INTO rezerwacje (klient_id, pracownik_id, usluga_id, data_rezerwacji, godzina_od, godzina_do, status, komentarz) VALUES
(4, 1, 1, '2026-09-14', '10:00:00', '11:00:00', 'potwierdzona',  NULL),
(5, 2, 3, '2026-09-14', '11:00:00', '11:30:00', 'oczekujaca',    'Klient prosi o krotkie boki'),
(6, 1, 5, '2026-09-15', '12:00:00', '13:30:00', 'zrealizowana',  NULL),
(4, 2, 4, '2026-09-10', '10:00:00', '10:20:00', 'anulowana',     'Klient odwolal wizyte');
