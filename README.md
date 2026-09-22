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


