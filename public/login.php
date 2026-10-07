<?php
require __DIR__ . '/../php/app.php';

// Wylogowanie: login.php?logout=1
if (isset($_GET['logout'])) {
    logout_user();
    session_start();                      // nowa sesja tylko na komunikat
    flash('success', 'Zostałeś wylogowany.');
    redirect('login.php');
}

require_guest();

$error = '';
$email = '';

if (is_post()) {
    csrf_verify();
    $email    = mb_strtolower(post('email'));
    $password = post_raw('password');

    if ($email === '' || $password === '') {
        $error = 'Podaj adres e-mail i hasło.';
    } else {
        $st = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch();

        // Gdy konta nie ma, i tak sprawdzamy hasło względem atrapy – czas odpowiedzi nie zdradza, czy e-mail istnieje
        $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforeusesomesillystringfore7OZSDOPqOtyW2';
        $ok = password_verify($password, $hash);

        if (!$row || !$ok) {
            $error = 'Nieprawidłowy adres e-mail lub hasło.';     // jeden wspólny komunikat
        } elseif (!(int)$row['is_active']) {
            $error = 'To konto jest nieaktywne. Skontaktuj się z administratorem.';
        } else {
            login_user((int)$row['id']);
            redirect(role_home(current_user()['role']));          // właściwy panel dla roli
        }
    }
}

page_header('Logowanie');
?>
<div class="card card-narrow">
    <h1>Logowanie</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <label>Adres e-mail
            <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email">
        </label>
        <label>Hasło
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn" type="submit">Zaloguj się</button>
    </form>
    <p class="muted">Nie masz konta? <a href="register.php">Zarejestruj się</a></p>
</div>
<?php page_footer();
