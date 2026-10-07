<?php
declare(strict_types=1);

/**
 * JEDEN plik wspólny dla całej aplikacji: konfiguracja, baza (PDO), funkcje pomocnicze,
 * logowanie/role oraz wspólny nagłówek i stopka HTML.
 * Każda strona w public/ zaczyna się od: require __DIR__ . '/../php/app.php';
 */

const ROLE_CLIENT   = 'client';
const ROLE_EMPLOYEE = 'employee';
const ROLE_ADMIN    = 'admin';

/* ---------- Ustawienia i start sesji ---------- */

ini_set('display_errors', '0');          // użytkownik nie widzi błędów technicznych
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    exit('Wystąpił nieoczekiwany błąd. Spróbuj ponownie później.');
});

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

/* ---------- Baza danych ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Domyślne dane (XAMPP). Własne dane wpisz w php/config.php (plik jest w .gitignore):
        // <?php return ['name' => 'moja_baza', 'user' => 'root', 'pass' => 'haslo'];
        $c = ['host' => 'localhost', 'name' => 'salon_fryzjerski', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'];
        if (is_file(__DIR__ . '/config.php')) {
            $c = array_merge($c, require __DIR__ . '/config.php');
        }
        try {
            $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}", $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connect: ' . $e->getMessage());
            http_response_code(500);
            exit('Nie można połączyć się z bazą danych. Spróbuj ponownie później.');
        }
    }
    return $pdo;
}

/* ---------- Funkcje pomocnicze ---------- */

/** Ochrona przed XSS – zawsze przy wypisywaniu danych w HTML. */
function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page): never
{
    header('Location: ' . $page);
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Tekst z formularza bez białych znaków na brzegach. */
function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Hasła nie są przycinane (spacje mogą być ich częścią). */
function post_raw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="field-error">' . e($errors[$key]) . '</span>' : '';
}

/* ---------- CSRF ---------- */

function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_verify(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', post_raw('csrf'))) {
        http_response_code(400);
        exit('Nieprawidłowe żądanie (token formularza wygasł). Odśwież stronę i spróbuj ponownie.');
    }
}

/* ---------- Walidacja ---------- */

/** Błędy [pole => komunikat] dla imienia, nazwiska, e-maila i telefonu. */
function validate_person(string $first, string $last, string $email, string $phone): array
{
    $err = [];
    $nameRe = '/^[\p{L} \'-]{2,50}$/u';

    if ($first === '')                      $err['first_name'] = 'Imię jest wymagane.';
    elseif (!preg_match($nameRe, $first))   $err['first_name'] = 'Imię może zawierać tylko litery (2–50 znaków).';

    if ($last === '')                       $err['last_name'] = 'Nazwisko jest wymagane.';
    elseif (!preg_match($nameRe, $last))    $err['last_name'] = 'Nazwisko może zawierać tylko litery (2–50 znaków).';

    if ($email === '')                      $err['email'] = 'Adres e-mail jest wymagany.';
    elseif (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))
                                            $err['email'] = 'Podaj poprawny adres e-mail.';

    if ($phone === '')                      $err['phone'] = 'Telefon jest wymagany.';
    elseif (!preg_match('/^\+?[0-9 \-]{9,15}$/', $phone))
                                            $err['phone'] = 'Telefon: 9–15 znaków (cyfry, spacje, myślniki, opcjonalnie + na początku).';
    return $err;
}

/** Min. 8 znaków, litera i cyfra. Zwraca komunikat błędu albo null. */
function password_error(string $p): ?string
{
    if (mb_strlen($p) < 8) {
        return 'Hasło musi mieć co najmniej 8 znaków.';
    }
    if (!preg_match('/\p{L}/u', $p) || !preg_match('/\d/', $p)) {
        return 'Hasło musi zawierać co najmniej jedną literę i jedną cyfrę.';
    }
    return null;
}

/* ---------- Logowanie i role ---------- */

/**
 * W sesji jest tylko id użytkownika. Resztę (rolę, aktywność) pobieramy z bazy
 * przy każdym żądaniu – zmiana roli lub blokada konta działa od razu.
 */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $st = db()->prepare('SELECT id, first_name, last_name, email, phone, role, is_active FROM users WHERE id = ?');
    $st->execute([(int)$id]);
    $row = $st->fetch();
    if (!$row || !(int)$row['is_active']) {
        logout_user();
        return $user = null;
    }
    return $user = $row;
}

function login_user(int $id): void
{
    session_regenerate_id(true);          // ochrona przed session fixation
    $_SESSION['user_id'] = $id;
    unset($_SESSION['csrf']);
}

function logout_user(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    session_destroy();
}

/** Strona startowa danej roli. */
function role_home(string $role): string
{
    return match ($role) {
        ROLE_ADMIN    => 'admin.php',
        ROLE_EMPLOYEE => 'employee.php',
        default       => 'client.php',
    };
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        flash('error', 'Zaloguj się, aby zobaczyć tę stronę.');
        redirect('login.php');
    }
    return $u;
}

/** Wpuszcza tylko podane role; reszta dostaje 403. Sprawdzane po stronie serwera. */
function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        page_header('Brak dostępu');
        echo '<div class="card"><h1>403 – brak dostępu</h1><p>Nie masz uprawnień do tej strony.</p>'
           . '<p><a class="btn" href="' . e(role_home($u['role'])) . '">Wróć do swojego panelu</a></p></div>';
        page_footer();
        exit;
    }
    return $u;
}

/** Dla login/register: zalogowany od razu trafia do swojego panelu. */
function require_guest(): void
{
    if (($u = current_user()) !== null) {
        redirect(role_home($u['role']));
    }
}

/* ---------- Wspólny wygląd strony ---------- */

function page_header(string $title): void
{
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | Aura Fryzur</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="index.php">Aura Fryzur</a>
        <input type="checkbox" id="nav-toggle" class="nav-toggle">
        <label for="nav-toggle" class="nav-burger" aria-label="Menu">&#9776;</label>
        <nav class="nav-links">
            <a href="index.php">Strona główna</a>
            <?php if ($u): ?>
                <a href="<?= e(role_home($u['role'])) ?>">Mój panel</a>
                <a href="profile.php">Profil</a>
                <span class="nav-user"><?= e($u['first_name']) ?></span>
                <a href="login.php?logout=1">Wyloguj</a>
            <?php else: ?>
                <a href="login.php">Logowanie</a>
                <a class="btn btn-small" href="register.php">Rejestracja</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
<?php
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alert alert-' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function page_footer(): void
{
    echo '</main><footer class="site-footer"><div class="container">&copy; ' . date('Y')
       . ' Aura Fryzur – projekt semestralny</div></footer></body></html>';
}

/** Gotowa strona panelu (używana przez admin.php, employee.php, client.php). */
function panel_page(string $role, string $title, string $text): void
{
    $u = require_role($role);
    page_header($title);
    echo '<div class="card"><h1>' . e($title) . '</h1><p>Witaj, <strong>'
       . e($u['first_name'] . ' ' . $u['last_name']) . '</strong>!</p><p class="muted">' . e($text) . '</p></div>';
    page_footer();
}
