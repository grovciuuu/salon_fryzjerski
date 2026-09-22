# Aura Fryzur

# System Rezerwacji Wizyt Online – Salon Fryzjerski

Kompleksowa aplikacja internetowa umożliwiająca klientom samodzielną rezerwację terminów usług fryzjerskich online, a pracownikom oraz administratorom efektywne zarządzanie harmonogramem.

---

## Autorzy i Informacje o Projekcie
* **Projekt Semestralny** – Przedmiot: *Tworzenie stron i aplikacji internetowych*
* **Klasa:** 5 Technikum Informatycznego
* **Okres realizacji:** wrzesień – listopad 2026 r.
* **Autorzy:** Szymon Myrcha, Martyna Brejer

---

## Opis Systemu

Aplikacja automatyzuje proces umawiania wizyt w salonie fryzjerskim. System dynamicznie weryfikuje dostępność fryzjerów, zapobiegając nakładaniu się terminów oraz rezerwacji poza godzinami pracy lub w przeszłości.

### Proces Rezerwacji
Proces przebiega w ścisłej kolejności:
`Wybór usługi`-> `Wybór pracownika` -> `Wybór daty` -> `Wybór godziny` -> `Potwierdzenie rezerwacji`

### System Rol i Uprawnień
* ** Klient:** Przegląda aktualną ofertę, rezerwuje dogodne terminy oraz zarządza historią i statusem własnych wizyt.
* ** Pracownik:** Posiada dostęp do indywidualnego panelu z harmonogramem swoich wizyt i możliwością zmiany ich statusu.
* ** Administrator:** Zarządza pełną bazą danych (CRUD usług, kategorii), kontami użytkowników i pracowników oraz posiada wgląd we wszystkie rezerwacje w systemie.

---

## Zastosowane Technologie

* **Backend:** PHP 8.4 (PDO, Prepared Statements)
* **Frontend:** HTML5, CSS3, JavaScript
* **Baza danych:** MySQL
* **Kontrola wersji:** Git & GitHub

---

## Struktura Repozytorium

```text
├── database/
│   └── database.sql      # Skrypt struktury bazy danych wraz z danymi testowymi
├── public/               # Główny katalog aplikacji (punkt wejścia)
│   ├── css/              # Arkusze stylów
│   ├── js/               # Skrypty JavaScript
│   └── assets/           # Pliki graficzne i ikony
└── README.md             # Dokumentacja projektu
```

---

## Instrukcja Uruchomienia

### Wymagania wstępne
* Serwer lokalny obsługujący PHP 8.4 oraz MySQL XAMPP

   Sklonuj repozytorium,
   Skonfiguruj bazę danych,
   Uruchom serwer MySQL,
   Utwórz nową pustą bazę danych,
   Zaimportuj plik struktury znajdujący się w `/database/database.sql`

---


Dane testowe do logowania

| Rola          | E-mail (login)              | Hasło     |
|---------------|------------------------------|-----------|
| Administrator | admin@salon.pl               | haslo123  |
| Pracownik     | marta.fryzjer@salon.pl       | haslo123  |
| Pracownik     | kamil.fryzjer@salon.pl       | haslo123  |
| Klient        | julia.klient@example.com     | haslo123  |


---

