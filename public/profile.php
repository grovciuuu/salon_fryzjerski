<?php
require __DIR__ . '/includes/app.php';
$user = require_login();              // dostępne dla każdej roli

$errors = $pwErrors = [];
$data = ['first_name' => $user['first_name'], 'last_name' => $user['last_name'],
         'email' => $user['email'], 'phone' => $user['phone'] ?? ''];

if (is_post()) {
    csrf_verify();

    /* ---- Edycja danych podstawowych ---- */
    if (post('action') === 'profile') {
        $data = [
            'first_name' => post('first_name'),
            'last_name'  => post('last_name'),
            'email'      => mb_strtolower(post('email')),
            'phone'      => post('phone'),
        ];
        $errors = validate_person($data['first_name'], $data['last_name'], $data['email'], $data['phone']);

        if (!isset($errors['email'])) {   // e-mail nie może należeć do kogoś innego
            $st = db()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
            $st->execute([$data['email'], $user['id']]);
            if ($st->fetch()) {
                $errors['email'] = 'Ten adres e-mail jest już używany przez inne konto.';
            }
        }

        if (!$errors) {
            try {
                // id bierzemy z SESJI, nie z formularza – nie da się edytować cudzego konta
                db()->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?')
                    ->execute([$data['first_name'], $data['last_name'], $data['email'], $data['phone'], $user['id']]);
                flash('success', 'Dane profilu zostały zapisane.');
                redirect('profile.php');
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors['email'] = 'Ten adres e-mail jest już używany przez inne konto.';
            }
        }
    }

    /* ---- Zmiana hasła (wymaga starego hasła) ---- */
    if (post('action') === 'password') {
        $old = post_raw('old_password');
        $new = post_raw('new_password');

        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user['id']]);

        if ($old === '' || !password_verify($old, (string)$st->fetchColumn())) {
            $pwErrors['old_password'] = 'Aktualne hasło jest nieprawidłowe.';
        }
        if ($msg = password_error($new)) {
            $pwErrors['new_password'] = $msg;
        }
        if ($new !== post_raw('new_password2')) {
            $pwErrors['new_password2'] = 'Hasła muszą być takie same.';
        }

        if (!$pwErrors) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('success', 'Hasło zostało zmienione.');
            redirect('profile.php');
        }
    }
}

page_header('Mój profil');
?>
<div class="grid-2">
    <div class="card">
        <h1>Mój profil</h1>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <label>Imię
                <input type="text" name="first_name" value="<?= e($data['first_name']) ?>" maxlength="50" required>
                <?= field_error($errors, 'first_name') ?>
            </label>
            <label>Nazwisko
                <input type="text" name="last_name" value="<?= e($data['last_name']) ?>" maxlength="50" required>
                <?= field_error($errors, 'last_name') ?>
            </label>
            <label>Adres e-mail
                <input type="email" name="email" value="<?= e($data['email']) ?>" maxlength="150" required>
                <?= field_error($errors, 'email') ?>
            </label>
            <label>Telefon
                <input type="tel" name="phone" value="<?= e($data['phone']) ?>" maxlength="20" required>
                <?= field_error($errors, 'phone') ?>
            </label>
            <p class="muted">Rola: <strong><?= e($user['role']) ?></strong></p>
            <button class="btn" type="submit">Zapisz zmiany</button>
        </form>
    </div>

    <div class="card">
        <h2>Zmiana hasła</h2>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <label>Aktualne hasło
                <input type="password" name="old_password" required autocomplete="current-password">
                <?= field_error($pwErrors, 'old_password') ?>
            </label>
            <label>Nowe hasło
                <input type="password" name="new_password" required autocomplete="new-password">
                <small>Min. 8 znaków, w tym litera i cyfra.</small>
                <?= field_error($pwErrors, 'new_password') ?>
            </label>
            <label>Powtórz nowe hasło
                <input type="password" name="new_password2" required autocomplete="new-password">
                <?= field_error($pwErrors, 'new_password2') ?>
            </label>
            <button class="btn" type="submit">Zmień hasło</button>
        </form>
    </div>
</div>
<?php page_footer();
