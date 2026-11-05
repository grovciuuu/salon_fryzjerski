<?php
require __DIR__ . '/includes/app.php';
require_guest();

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    csrf_verify();
    $old = [
        'first_name' => post('first_name'),
        'last_name'  => post('last_name'),
        'email'      => mb_strtolower(post('email')),
        'phone'      => post('phone'),
    ];
    $password  = post_raw('password');
    $password2 = post_raw('password2');

    // 1. Walidacja po stronie serwera
    $errors = validate_person($old['first_name'], $old['last_name'], $old['email'], $old['phone']);
    if ($password === '') {
        $errors['password'] = 'Hasło jest wymagane.';
    } elseif ($msg = password_error($password)) {
        $errors['password'] = $msg;
    }
    if ($password !== $password2) {
        $errors['password2'] = 'Hasła muszą być takie same.';
    }

    // 2. Unikalność e-maila
    if (!isset($errors['email'])) {
        $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$old['email']]);
        if ($st->fetch()) {
            $errors['email'] = 'Konto z tym adresem e-mail już istnieje.';
        }
    }

    // 3. Zapis (rola zawsze "client" – nigdy nie bierzemy jej z formularza)
    if (!$errors) {
        try {
            db()->prepare('INSERT INTO users (first_name, last_name, email, password_hash, phone, role) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$old['first_name'], $old['last_name'], $old['email'],
                           password_hash($password, PASSWORD_DEFAULT), $old['phone'], ROLE_CLIENT]);
            flash('success', 'Konto zostało utworzone. Możesz się zalogować.');
            redirect('login.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') {   // 23000 = naruszenie UNIQUE (e-mail zajęty w międzyczasie)
                throw $ex;
            }
            $errors['email'] = 'Konto z tym adresem e-mail już istnieje.';
        }
    }
}

page_header('Rejestracja');
?>
<div class="card card-narrow">
    <h1>Rejestracja</h1>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <label>Imię
            <input type="text" name="first_name" value="<?= e($old['first_name']) ?>" maxlength="50" required>
            <?= field_error($errors, 'first_name') ?>
        </label>
        <label>Nazwisko
            <input type="text" name="last_name" value="<?= e($old['last_name']) ?>" maxlength="50" required>
            <?= field_error($errors, 'last_name') ?>
        </label>
        <label>Adres e-mail
            <input type="email" name="email" value="<?= e($old['email']) ?>" maxlength="150" required>
            <?= field_error($errors, 'email') ?>
        </label>
        <label>Telefon
            <input type="tel" name="phone" value="<?= e($old['phone']) ?>" maxlength="20" required>
            <?= field_error($errors, 'phone') ?>
        </label>
        <label>Hasło
            <input type="password" name="password" required autocomplete="new-password">
            <small>Min. 8 znaków, w tym litera i cyfra.</small>
            <?= field_error($errors, 'password') ?>
        </label>
        <label>Powtórz hasło
            <input type="password" name="password2" required autocomplete="new-password">
            <?= field_error($errors, 'password2') ?>
        </label>
        <button class="btn" type="submit">Zarejestruj się</button>
    </form>
    <p class="muted">Masz już konto? <a href="login.php">Zaloguj się</a></p>
</div>
<?php page_footer();
