<?php
declare(strict_types=1);

/**
 * Wspólne funkcje panelu administratora. Dołączają je wszystkie pliki admin*.php:
 *     require __DIR__ . '/includes/admin_helpers.php';
 * (ten plik sam dołącza app.php – sesja, baza, logowanie, role).
 */
require_once __DIR__ . '/app.php';

/* ---------- Słowniki (jedno miejsce prawdy) ---------- */

const STATUSES = [
    'pending'   => 'Oczekująca',
    'confirmed' => 'Potwierdzona',
    'completed' => 'Zrealizowana',
    'cancelled' => 'Anulowana',
];
const ROLES = ['client' => 'Klient', 'employee' => 'Pracownik', 'admin' => 'Administrator'];
const DAYS  = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'];

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

/** Stopka panelu: skrypt z potwierdzeniami + zamknięcie strony. */
function admin_footer(): void
{
    echo '<script src="js/admin.js"></script>';
    page_footer();
}

/* ---------- Skróty do bazy (zawsze prepared statements) ---------- */

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_val(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

/** INSERT/UPDATE/DELETE – zwraca liczbę zmienionych wierszy. */
function db_run(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/* ---------- Odczyt parametrów ---------- */

/** Liczba całkowita z GET (0, gdy brak lub niepoprawna). */
function get_int(string $key): int
{
    $v = $_GET[$key] ?? '';
    return (is_string($v) && ctype_digit($v) && strlen($v) < 10) ? (int)$v : 0;
}

function post_int(string $key): int
{
    $v = $_POST[$key] ?? '';
    return (is_string($v) && ctype_digit($v) && strlen($v) < 10) ? (int)$v : 0;
}

function get_str(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** Bezpieczny wzorzec do LIKE (znaki % i _ wpisane przez użytkownika tracą znaczenie specjalne). */
function like(string $s): string
{
    return '%' . addcslashes($s, '%_\\') . '%';
}

/* ---------- Adresy z filtrami i powrót po akcji ---------- */

/** Bieżące parametry GET z podmienionymi wartościami, np. qs(['page' => 2]) => "?q=ala&page=2". */
function qs(array $change = []): string
{
    $p = array_filter(array_merge($_GET, $change), fn($v) => $v !== null && $v !== '');
    return $p ? '?' . http_build_query($p) : '';
}

/** Ukryte pole zapamiętujące filtry/stronę, żeby po akcji wrócić na ten sam widok. */
function qs_field(): string
{
    return '<input type="hidden" name="qs" value="' . e($_SERVER['QUERY_STRING'] ?? '') . '">';
}

/** Powrót na stronę $page z zapamiętanymi filtrami (z zapytania zostawiamy tylko bezpieczne znaki). */
function redirect_back(string $page): never
{
    $qs = preg_replace('/[^A-Za-z0-9_=&%.\-\[\]]/', '', post_raw('qs'));
    redirect($page . ($qs !== '' ? '?' . $qs : ''));
}

/* ---------- Paginacja ---------- */

/** Zwraca [aktualna strona, liczba stron, przesunięcie OFFSET]. */
function paginate(int $total, int $perPage): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, get_int('page')), $pages);
    return [$page, $pages, ($page - 1) * $perPage];
}

function pagination_html(int $page, int $pages, string $file): string
{
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="pagination" aria-label="Strony">';
    if ($page > 1) {
        $out .= '<a href="' . e($file . qs(['page' => $page - 1])) . '">&laquo; Poprzednia</a>';
    }
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $out .= $i === $page
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . e($file . qs(['page' => $i])) . '">' . $i . '</a>';
    }
    if ($page < $pages) {
        $out .= '<a href="' . e($file . qs(['page' => $page + 1])) . '">Następna &raquo;</a>';
    }
    return $out . '</nav>';
}

/* ---------- Formatowanie ---------- */

function money(string|float|int $v): string
{
    return number_format((float)$v, 2, ',', ' ') . ' zł';
}

/** "09:00:00" -> "09:00" */
function hm(?string $t): string
{
    return substr((string)$t, 0, 5);
}

function badge(string $class, string $text): string
{
    return '<span class="badge badge-' . e($class) . '">' . e($text) . '</span>';
}

function status_badge(string $status): string
{
    return badge($status, STATUSES[$status] ?? $status);
}

/** Mały formularz zmiany statusu rezerwacji (lista i szczegóły używają tego samego). */
function status_form(array $r): string
{
    $options = '';
    foreach (STATUSES as $key => $label) {
        $options .= '<option value="' . e($key) . '"' . ($key === $r['status'] ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return '<form method="post" action="admin_reservations.php" class="inline-form" data-confirm-status="cancelled">'
        . csrf_field()
        . '<input type="hidden" name="action" value="status">'
        . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . qs_field()
        . '<select name="status" aria-label="Status">' . $options . '</select> '
        . '<button class="btn btn-small" type="submit">Zapisz</button></form>';
}
