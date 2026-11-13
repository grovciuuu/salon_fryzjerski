<?php
declare(strict_types=1);

/**
 * Elementy wspólne tylko dla panelu administratora. Dołączają je pliki admin*.php:
 *     require __DIR__ . '/includes/admin_helpers.php';
 * Funkcje ogólne (baza, paginacja, formatowanie) są w functions.php.
 */
require_once __DIR__ . '/functions.php';

/* Wspólne fragmenty SQL dla rezerwacji (dashboard i lista używają tych samych zapytań) */
const RESERVATION_COLS =
    'r.id, r.reservation_date, r.start_time, r.end_time, r.status, r.comment, r.created_at,
     c.first_name AS c_first, c.last_name AS c_last, c.email AS c_email, c.phone AS c_phone,
     e.id AS emp_id, eu.first_name AS e_first, eu.last_name AS e_last,
     s.name AS service, s.price, s.duration';
const RESERVATION_FROM =
    ' FROM reservations r
       JOIN users c      ON c.id  = r.user_id
       JOIN employees e  ON e.id  = r.employee_id
       JOIN users eu     ON eu.id = e.user_id
       JOIN services s   ON s.id  = r.service_id ';

/* ---------- Układ strony panelu ---------- */

/** Nagłówek strony + menu panelu. $active = klucz podświetlonej zakładki. */
function admin_header(string $title, string $active): void
{
    page_header($title);
    $items = [
        'dashboard'    => ['admin.php', 'Dashboard'],
        'reservations' => ['admin_reservations.php', 'Rezerwacje'],
        'services'     => ['admin_services.php', 'Usługi'],
        'categories'   => ['admin_categories.php', 'Kategorie'],
        'employees'    => ['admin_employees.php', 'Pracownicy'],
        'users'        => ['admin_users.php', 'Użytkownicy'],
    ];
    echo '<nav class="subnav">';
    foreach ($items as $key => [$file, $label]) {
        echo '<a href="' . e($file) . '"' . ($key === $active ? ' class="active"' : '') . '>' . e($label) . '</a>';
    }
    echo '</nav>';
}

/** Stopka panelu (skrypt z potwierdzeniami ładuje już page_footer()). */
function admin_footer(): void
{
    page_footer();
}
