<?php
require __DIR__ . '/includes/admin_helpers.php';
$admin = require_role(ROLE_ADMIN);

const SELF = 'admin_employees.php';

/** Pracownik razem z danymi konta (imię, e-mail...) albo null. */
function load_employee(int $id): ?array
{
    return db_one(
        'SELECT e.id, e.user_id, e.bio, e.is_active, u.first_name, u.last_name, u.email, u.phone
           FROM employees e JOIN users u ON u.id = e.user_id WHERE e.id = ?',
        [$id]
    );
}

function valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

/* Który widok pokazać: lista / nowy pracownik (?new=1) / edycja (?id=N) */
$mode  = isset($_GET['new']) ? 'new' : (get_int('id') > 0 ? 'edit' : 'list');
$empId = get_int('id');

$errors      = [];     // błędy formularza danych / nowego pracownika
$availErrors = [];     // błędy godzin pracy (klucz = numer dnia)
$posted      = null;   // wartości z formularza po błędzie (żeby nic nie znikało)
$postedAvail = null;
$postedSrv   = null;

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id     = post_int('id');

    /* ---------- Nowy pracownik: konto (rola employee) + wiersz w employees ---------- */
    if ($action === 'create') {
        $mode = 'new';
        $posted = ['first_name' => post('first_name'), 'last_name' => post('last_name'),
                   'email' => mb_strtolower(post('email')), 'phone' => post('phone'), 'bio' => post('bio')];
        $password = post_raw('password');

        $errors = validate_person($posted['first_name'], $posted['last_name'], $posted['email'], $posted['phone']);
        if ($password === '') {
            $errors['password'] = 'Hasło jest wymagane.';
        } elseif ($m = password_error($password)) {
            $errors['password'] = $m;
        }
        if (mb_strlen($posted['bio']) > 1000) {
            $errors['bio'] = 'Opis może mieć maksymalnie 1000 znaków.';
        }
        if (!isset($errors['email']) && db_val('SELECT 1 FROM users WHERE email = ?', [$posted['email']])) {
            $errors['email'] = 'Konto z tym adresem e-mail już istnieje.';
        }

        if (!$errors) {
            $pdo = db();
            try {
                $pdo->beginTransaction();               // oba zapisy albo żaden
                db_run(
                    'INSERT INTO users (first_name, last_name, email, password_hash, phone, role) VALUES (?, ?, ?, ?, ?, ?)',
                    [$posted['first_name'], $posted['last_name'], $posted['email'],
                     password_hash($password, PASSWORD_DEFAULT), $posted['phone'], ROLE_EMPLOYEE]
                );
                $userId = (int)$pdo->lastInsertId();
                db_run('INSERT INTO employees (user_id, bio) VALUES (?, ?)', [$userId, $posted['bio'] !== '' ? $posted['bio'] : null]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->commit();
                flash('success', 'Dodano pracownika. Przypisz mu teraz usługi i ustaw godziny pracy.');
                redirect(SELF . '?id=' . $newId);
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors['email'] = 'Konto z tym adresem e-mail już istnieje.';
            }
        }
    }

    /* ---------- Edycja danych i aktywności ---------- */
    if ($action === 'data') {
        $mode = 'edit';
        $empId = $id;
        $emp = load_employee($id);
        if (!$emp) {
            flash('error', 'Nie znaleziono pracownika.');
            redirect(SELF);
        }
        $posted = ['first_name' => post('first_name'), 'last_name' => post('last_name'),
                   'email' => mb_strtolower(post('email')), 'phone' => post('phone'), 'bio' => post('bio'),
                   'is_active' => isset($_POST['is_active']) ? 1 : 0];

        $errors = validate_person($posted['first_name'], $posted['last_name'], $posted['email'], $posted['phone']);
        if (mb_strlen($posted['bio']) > 1000) {
            $errors['bio'] = 'Opis może mieć maksymalnie 1000 znaków.';
        }
        if (!isset($errors['email']) && db_val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$posted['email'], $emp['user_id']])) {
            $errors['email'] = 'Ten adres e-mail jest już używany przez inne konto.';
        }

        if (!$errors) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                db_run('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?',
                       [$posted['first_name'], $posted['last_name'], $posted['email'], $posted['phone'], $emp['user_id']]);
                db_run('UPDATE employees SET bio = ?, is_active = ? WHERE id = ?',
                       [$posted['bio'] !== '' ? $posted['bio'] : null, $posted['is_active'], $id]);
                $pdo->commit();
                flash('success', 'Dane pracownika zostały zapisane.');
                redirect(SELF . '?id=' . $id);
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors['email'] = 'Ten adres e-mail jest już używany przez inne konto.';
            }
        }
    }

    /* ---------- Szybka zmiana aktywności z listy ---------- */
    if ($action === 'toggle') {
        db_run('UPDATE employees SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('success', 'Zmieniono aktywność pracownika.');
        redirect_back(SELF);
    }

    /* ---------- Przypisanie usług (relacja N:M employee_services) ---------- */
    if ($action === 'services') {
        $mode = 'edit';
        $empId = $id;
        if (!load_employee($id)) {
            flash('error', 'Nie znaleziono pracownika.');
            redirect(SELF);
        }
        $sent  = is_array($_POST['services'] ?? null) ? array_map('intval', $_POST['services']) : [];
        $known = array_map('intval', array_column(db_all('SELECT id FROM services'), 'id'));
        $ids   = array_values(array_unique(array_intersect($sent, $known)));   // tylko istniejące usługi

        $pdo = db();
        $pdo->beginTransaction();
        db_run('DELETE FROM employee_services WHERE employee_id = ?', [$id]);
        foreach ($ids as $sid) {
            db_run('INSERT INTO employee_services (employee_id, service_id) VALUES (?, ?)', [$id, $sid]);
        }
        $pdo->commit();
        flash('success', 'Przypisane usługi zostały zapisane.');
        redirect(SELF . '?id=' . $id);
    }

    /* ---------- Godziny pracy (employee_availability) ---------- */
    if ($action === 'availability') {
        $mode = 'edit';
        $empId = $id;
        if (!load_employee($id)) {
            flash('error', 'Nie znaleziono pracownika.');
            redirect(SELF);
        }
        $on    = is_array($_POST['day'] ?? null) ? $_POST['day'] : [];
        $start = is_array($_POST['start'] ?? null) ? $_POST['start'] : [];
        $end   = is_array($_POST['end'] ?? null) ? $_POST['end'] : [];

        $postedAvail = [];
        foreach (DAYS as $d => $label) {
            $s = is_string($start[$d] ?? null) ? trim($start[$d]) : '';
            $e = is_string($end[$d] ?? null) ? trim($end[$d]) : '';
            $postedAvail[$d] = ['on' => isset($on[$d]), 'start' => $s, 'end' => $e];
            if ($postedAvail[$d]['on']) {
                if (!valid_time($s) || !valid_time($e)) {
                    $availErrors[$d] = 'Podaj poprawne godziny.';
                } elseif ($e <= $s) {
                    $availErrors[$d] = 'Koniec pracy musi być później niż początek.';
                }
            }
        }

        if (!$availErrors) {
            $pdo = db();
            $pdo->beginTransaction();
            db_run('DELETE FROM employee_availability WHERE employee_id = ?', [$id]);
            foreach ($postedAvail as $d => $a) {
                if ($a['on']) {
                    db_run('INSERT INTO employee_availability (employee_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)',
                           [$id, $d, $a['start'] . ':00', $a['end'] . ':00']);
                }
            }
            $pdo->commit();
            flash('success', 'Godziny pracy zostały zapisane.');
            redirect(SELF . '?id=' . $id);
        }
    }
}

/* ---------- Dane do wyświetlenia ---------- */
$emp = null;
if ($mode === 'edit') {
    $emp = load_employee($empId);
    if (!$emp) {
        flash('error', 'Nie znaleziono pracownika.');
        redirect(SELF);
    }
}

admin_header('Pracownicy', 'employees');

/* ================= LISTA ================= */
if ($mode === 'list'):
    $rows = db_all(
        'SELECT e.id, e.is_active, u.first_name, u.last_name, u.email, u.phone,
                (SELECT COUNT(*) FROM employee_services es WHERE es.employee_id = e.id) AS srv,
                (SELECT COUNT(*) FROM employee_availability a WHERE a.employee_id = e.id) AS days
           FROM employees e JOIN users u ON u.id = e.user_id ORDER BY u.last_name, u.first_name'
    ); ?>
<div class="toolbar"><h1>Pracownicy</h1><a class="btn" href="<?= SELF ?>?new=1">+ Dodaj pracownika</a></div>
<div class="card">
    <?php if (!$rows): ?>
        <p class="muted">Brak pracowników.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Pracownik</th><th>E-mail</th><th>Telefon</th><th>Usługi</th><th>Dni pracy</th><th>Status</th><th>Akcje</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
                    <td><?= e($r['email']) ?></td>
                    <td><?= e($r['phone']) ?></td>
                    <td><?= (int)$r['srv'] ?></td>
                    <td><?= (int)$r['days'] ?></td>
                    <td><?= $r['is_active'] ? badge('on', 'Aktywny') : badge('off', 'Nieaktywny') ?></td>
                    <td class="actions">
                        <a class="btn btn-small btn-secondary" href="<?= SELF ?>?id=<?= (int)$r['id'] ?>">Edytuj</a>
                        <form method="post" action="<?= SELF ?>" class="inline-form"
                              data-confirm="<?= $r['is_active'] ? 'Dezaktywować' : 'Aktywować' ?> pracownika <?= e($r['first_name'] . ' ' . $r['last_name']) ?>?">
                            <?= csrf_field() ?><?= qs_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-small btn-secondary" type="submit"><?= $r['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($mode === 'new'):
    $v = $posted ?? ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'bio' => '']; ?>
<div class="toolbar"><h1>Nowy pracownik</h1><a class="btn btn-secondary" href="<?= SELF ?>">&laquo; Wróć do listy</a></div>
<div class="card card-narrow">
    <p class="muted">Zostanie utworzone konto z rolą „Pracownik”, na które fryzjer zaloguje się e-mailem i hasłem.</p>
    <form method="post" action="<?= SELF ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label>Imię <input type="text" name="first_name" value="<?= e($v['first_name']) ?>" maxlength="50" required><?= field_error($errors, 'first_name') ?></label>
        <label>Nazwisko <input type="text" name="last_name" value="<?= e($v['last_name']) ?>" maxlength="50" required><?= field_error($errors, 'last_name') ?></label>
        <label>Adres e-mail <input type="email" name="email" value="<?= e($v['email']) ?>" maxlength="150" required><?= field_error($errors, 'email') ?></label>
        <label>Telefon <input type="tel" name="phone" value="<?= e($v['phone']) ?>" maxlength="20" required><?= field_error($errors, 'phone') ?></label>
        <label>Hasło <input type="password" name="password" required autocomplete="new-password">
            <small>Min. 8 znaków, w tym litera i cyfra.</small><?= field_error($errors, 'password') ?></label>
        <label>Opis / specjalizacja (opcjonalnie) <textarea name="bio" rows="3" maxlength="1000"><?= e($v['bio']) ?></textarea><?= field_error($errors, 'bio') ?></label>
        <button class="btn" type="submit">Dodaj pracownika</button>
    </form>
</div>

<?php else: /* ================= EDYCJA PRACOWNIKA ================= */
    $v = $posted ?? ['first_name' => $emp['first_name'], 'last_name' => $emp['last_name'], 'email' => $emp['email'],
                     'phone' => (string)$emp['phone'], 'bio' => (string)$emp['bio'], 'is_active' => (int)$emp['is_active']];

    $assigned = array_map('intval', array_column(db_all('SELECT service_id FROM employee_services WHERE employee_id = ?', [$empId]), 'service_id'));
    $allServices = db_all('SELECT s.id, s.name, s.is_active, c.name AS category
                             FROM services s JOIN service_categories c ON c.id = s.category_id ORDER BY c.name, s.name');

    $avail = [];
    $multi = false;
    if ($postedAvail !== null) {
        $avail = $postedAvail;
    } else {
        foreach (DAYS as $d => $label) {
            $avail[$d] = ['on' => false, 'start' => '09:00', 'end' => '17:00'];
        }
        // Jeśli dzień ma kilka przedziałów, łączymy je w jeden (najwcześniejszy start – najpóźniejszy koniec)
        foreach (db_all('SELECT day_of_week, MIN(start_time) AS s, MAX(end_time) AS e, COUNT(*) AS n
                           FROM employee_availability WHERE employee_id = ? GROUP BY day_of_week', [$empId]) as $a) {
            $avail[(int)$a['day_of_week']] = ['on' => true, 'start' => hm($a['s']), 'end' => hm($a['e'])];
            $multi = $multi || (int)$a['n'] > 1;
        }
    }
    $futureRes = (int)db_val("SELECT COUNT(*) FROM reservations WHERE employee_id = ? AND status IN ('pending','confirmed') AND reservation_date >= ?",
                             [$empId, date('Y-m-d')]); ?>
<div class="toolbar">
    <h1><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></h1>
    <a class="btn btn-secondary" href="<?= SELF ?>">&laquo; Wróć do listy</a>
</div>
<p class="muted">Aktywne przyszłe rezerwacje tego pracownika: <strong><?= $futureRes ?></strong></p>

<div class="grid-2">
    <div class="card">
        <h2>Dane i aktywność</h2>
        <form method="post" action="<?= SELF ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="data">
            <input type="hidden" name="id" value="<?= (int)$empId ?>">
            <label>Imię <input type="text" name="first_name" value="<?= e($v['first_name']) ?>" maxlength="50" required><?= field_error($errors, 'first_name') ?></label>
            <label>Nazwisko <input type="text" name="last_name" value="<?= e($v['last_name']) ?>" maxlength="50" required><?= field_error($errors, 'last_name') ?></label>
            <label>Adres e-mail <input type="email" name="email" value="<?= e($v['email']) ?>" maxlength="150" required><?= field_error($errors, 'email') ?></label>
            <label>Telefon <input type="tel" name="phone" value="<?= e($v['phone']) ?>" maxlength="20" required><?= field_error($errors, 'phone') ?></label>
            <label>Opis / specjalizacja <textarea name="bio" rows="3" maxlength="1000"><?= e($v['bio']) ?></textarea><?= field_error($errors, 'bio') ?></label>
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= $v['is_active'] ? ' checked' : '' ?>> Pracownik aktywny</label>
            <button class="btn" type="submit">Zapisz dane</button>
        </form>
    </div>

    <div class="card">
        <h2>Wykonywane usługi</h2>
        <form method="post" action="<?= SELF ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="services">
            <input type="hidden" name="id" value="<?= (int)$empId ?>">
            <?php $lastCat = null; foreach ($allServices as $s): ?>
                <?php if ($s['category'] !== $lastCat): $lastCat = $s['category']; ?>
                    <p class="group-title"><?= e($s['category']) ?></p>
                <?php endif; ?>
                <label class="check">
                    <input type="checkbox" name="services[]" value="<?= (int)$s['id'] ?>"<?= in_array((int)$s['id'], $assigned, true) ? ' checked' : '' ?>>
                    <?= e($s['name']) ?><?= $s['is_active'] ? '' : ' (nieaktywna)' ?>
                </label>
            <?php endforeach; ?>
            <?php if (!$allServices): ?><p class="muted">Najpierw dodaj usługi.</p><?php endif; ?>
            <button class="btn" type="submit">Zapisz usługi</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>Godziny pracy</h2>
    <p class="muted">Zaznacz dni, w których pracownik przyjmuje klientów, i ustaw godziny. Na tej podstawie system w etapie 4 wyliczy dostępne terminy.</p>
    <?php if ($multi): ?>
        <div class="alert alert-error">Ten pracownik ma w niektórych dniach kilka przedziałów – po zapisaniu zostaną połączone w jeden.</div>
    <?php endif; ?>
    <form method="post" action="<?= SELF ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="availability">
        <input type="hidden" name="id" value="<?= (int)$empId ?>">
        <?php foreach (DAYS as $d => $label): ?>
            <div class="avail-row">
                <label class="check"><input type="checkbox" name="day[<?= $d ?>]" value="1"<?= $avail[$d]['on'] ? ' checked' : '' ?>> <?= e($label) ?></label>
                <input type="time" name="start[<?= $d ?>]" value="<?= e($avail[$d]['start']) ?>" aria-label="Początek – <?= e($label) ?>">
                <input type="time" name="end[<?= $d ?>]" value="<?= e($avail[$d]['end']) ?>" aria-label="Koniec – <?= e($label) ?>">
            </div>
            <?= field_error($availErrors, (string)$d) ?>
        <?php endforeach; ?>
        <button class="btn" type="submit">Zapisz godziny pracy</button>
    </form>
</div>
<?php endif;
admin_footer();
