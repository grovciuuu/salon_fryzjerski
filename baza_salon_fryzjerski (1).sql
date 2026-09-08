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

CREATE TABLE wyjatki_dostepnosci (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pracownik_id    INT UNSIGNED NOT NULL,
    data            DATE NOT NULL,
    typ             ENUM('urlop', 'zwolnienie', 'zmiana_godzin') NOT NULL,
    godzina_od      TIME NULL,
    godzina_do      TIME NULL,

    CONSTRAINT fk_wyjatki_pracownik
        FOREIGN KEY (pracownik_id) REFERENCES pracownicy(id)
        ON UPDATE CASCADE ON DELETE CASCADE,

    CONSTRAINT uq_wyjatki_pracownik_data UNIQUE (pracownik_id, data),
    CONSTRAINT chk_wyjatki_spojnosc CHECK (
        (typ = 'zmiana_godzin' AND godzina_od IS NOT NULL AND godzina_do IS NOT NULL AND godzina_od < godzina_do)
        OR (typ IN ('urlop', 'zwolnienie') AND godzina_od IS NULL AND godzina_do IS NULL)
    )
) ENGINE=InnoDB;

CREATE INDEX idx_wyjatki_pracownik_data ON wyjatki_dostepnosci (pracownik_id, data);

CREATE TABLE dni_wolne_salonu (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data    DATE NOT NULL,
    opis    VARCHAR(150) NULL,

    CONSTRAINT uq_dni_wolne_data UNIQUE (data)
) ENGINE=InnoDB;

CREATE TABLE rezerwacje (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    klient_id           INT UNSIGNED NOT NULL,
    pracownik_id        INT UNSIGNED NOT NULL,
    usluga_id           INT UNSIGNED NOT NULL,
    data_rezerwacji     DATE NOT NULL,
    godzina_od          TIME NOT NULL,
    godzina_do          TIME NOT NULL,
    cena                DECIMAL(8,2) NOT NULL,
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

    CONSTRAINT chk_rezerwacje_godziny CHECK (godzina_od < godzina_do),
    CONSTRAINT chk_rezerwacje_cena CHECK (cena >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_rezerwacje_pracownik_data_godziny
    ON rezerwacje (pracownik_id, data_rezerwacji, godzina_od, godzina_do);

CREATE INDEX idx_rezerwacje_klient ON rezerwacje (klient_id);

CREATE INDEX idx_rezerwacje_status ON rezerwacje (status);

CREATE TABLE historia_statusow_rezerwacji (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rezerwacja_id       INT UNSIGNED NOT NULL,
    status_poprzedni    ENUM('oczekujaca', 'potwierdzona', 'zrealizowana', 'anulowana') NULL,
    status_nowy         ENUM('oczekujaca', 'potwierdzona', 'zrealizowana', 'anulowana') NOT NULL,
    data_zmiany         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_historia_rezerwacja
        FOREIGN KEY (rezerwacja_id) REFERENCES rezerwacje(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_historia_rezerwacja ON historia_statusow_rezerwacji (rezerwacja_id);

CREATE TABLE opinie (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rezerwacja_id       INT UNSIGNED NOT NULL,
    ocena               TINYINT UNSIGNED NOT NULL,
    komentarz           TEXT NULL,
    data_utworzenia     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_opinie_rezerwacja UNIQUE (rezerwacja_id),
    CONSTRAINT fk_opinie_rezerwacja
        FOREIGN KEY (rezerwacja_id) REFERENCES rezerwacje(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_opinie_ocena CHECK (ocena BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE zdjecia_realizacji (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pracownik_id    INT UNSIGNED NOT NULL,
    usluga_id       INT UNSIGNED NULL,
    url_zdjecia     VARCHAR(255) NOT NULL,
    opis            VARCHAR(255) NULL,
    data_dodania    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_zdjecia_pracownik
        FOREIGN KEY (pracownik_id) REFERENCES pracownicy(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_zdjecia_usluga
        FOREIGN KEY (usluga_id) REFERENCES uslugi(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_zdjecia_pracownik ON zdjecia_realizacji (pracownik_id);
CREATE INDEX idx_zdjecia_usluga ON zdjecia_realizacji (usluga_id);

SET FOREIGN_KEY_CHECKS = 1;

DELIMITER $$

CREATE TRIGGER trg_rezerwacje_konflikt_insert
BEFORE INSERT ON rezerwacje
FOR EACH ROW
BEGIN
    DECLARE liczba_konfliktow INT;

    SELECT COUNT(*) INTO liczba_konfliktow
    FROM rezerwacje
    WHERE pracownik_id = NEW.pracownik_id
      AND data_rezerwacji = NEW.data_rezerwacji
      AND status <> 'anulowana'
      AND NEW.godzina_od < godzina_do
      AND NEW.godzina_do > godzina_od;

    IF liczba_konfliktow > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Termin koliduje z inna aktywna rezerwacja tego pracownika';
    END IF;
END$$

CREATE TRIGGER trg_rezerwacje_konflikt_update
BEFORE UPDATE ON rezerwacje
FOR EACH ROW
BEGIN
    DECLARE liczba_konfliktow INT;

    IF NEW.status <> 'anulowana' THEN
        SELECT COUNT(*) INTO liczba_konfliktow
        FROM rezerwacje
        WHERE pracownik_id = NEW.pracownik_id
          AND data_rezerwacji = NEW.data_rezerwacji
          AND status <> 'anulowana'
          AND id <> NEW.id
          AND NEW.godzina_od < godzina_do
          AND NEW.godzina_do > godzina_od;

        IF liczba_konfliktow > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Termin koliduje z inna aktywna rezerwacja tego pracownika';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_rezerwacje_log_status_insert
AFTER INSERT ON rezerwacje
FOR EACH ROW
BEGIN
    INSERT INTO historia_statusow_rezerwacji (rezerwacja_id, status_poprzedni, status_nowy)
    VALUES (NEW.id, NULL, NEW.status);
END$$

CREATE TRIGGER trg_rezerwacje_log_status_update
AFTER UPDATE ON rezerwacje
FOR EACH ROW
BEGIN
    IF NEW.status <> OLD.status THEN
        INSERT INTO historia_statusow_rezerwacji (rezerwacja_id, status_poprzedni, status_nowy)
        VALUES (NEW.id, OLD.status, NEW.status);
    END IF;
END$$

DELIMITER ;


